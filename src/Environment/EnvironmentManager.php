<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Environment;

use MrKoopie\LaravelAiHarness\Config\Config;
use MrKoopie\LaravelAiHarness\Config\ConfigLoader;
use MrKoopie\LaravelAiHarness\Process\ProcessRunner;
use Symfony\Component\Console\Output\OutputInterface;

final readonly class EnvironmentManager
{
    public function __construct(
        private ConfigLoader $configLoader,
        private CommandFactory $commands,
        private ProcessRunner $processes,
        private EnvironmentFile $environmentFile,
        private StateStore $state,
    ) {}

    public function setup(string $root, OutputInterface $output): int
    {
        $config = $this->configLoader->load($root);
        if (! is_file($root.'/vendor/autoload.php')) {
            $output->writeln('<info>Installing Composer dependencies</info>');
            $status = $this->processes->run($this->commands->bootstrapComposer(), $root, $output);

            if ($status !== 0) {
                return $status;
            }
        }

        if ($this->environmentFile->ensure($root)) {
            $output->writeln('<info>Created .env from .env.example</info>');
        }

        $usesMySql = $this->usesMySql($config);

        if ($usesMySql) {
            $this->environmentFile->configureMySql($root, $config->runtime === Runtime::Sail);
            $output->writeln('<info>Configured MySQL environment values</info>');
        }

        if ($this->requiresSail($config)) {
            $output->writeln('<info>Starting configured Sail containers</info>');
            $status = $this->processes->run($this->commands->servicesUp($config, $root), $root, $output);

            if ($status !== 0) {
                return $status;
            }
        }

        if ($usesMySql) {
            $output->writeln('<info>Ensuring checkout-specific MySQL databases</info>');
            $status = $this->processes->run($this->commands->ensureMySqlDatabases($root), $root, $output);

            if ($status !== 0) {
                return $status;
            }

            $this->state->recordMySqlDatabases($root);
        }

        if ($config->runtime === Runtime::Herd) {
            $status = $this->setupHerd($config, $root, $output);

            if ($status !== 0) {
                return $status;
            }

            $this->environmentFile->setAppUrl($root, 'https://'.SiteName::forPath($root).'.test');
            $output->writeln('<info>Configured APP_URL for the Herd site</info>');
        }

        if (is_file($root.'/artisan') && $this->environmentFile->appKeyMissing($root)) {
            $output->writeln('<info>Generating Laravel application key</info>');

            $status = $this->processes->run(
                $this->commands->runtime($config, 'artisan', ['key:generate', '--ansi'], $root),
                $root,
                $output,
            );

            if ($status !== 0) {
                return $status;
            }
        }

        if (is_file($root.'/artisan')) {
            $createdTesting = $usesMySql
                ? $this->environmentFile->ensureMySqlTesting($root, $config->runtime === Runtime::Sail)
                : $this->environmentFile->ensureTesting($root);

            if ($createdTesting) {
                $message = $usesMySql
                    ? 'Created .env.testing with the Sail testing database'
                    : 'Created .env.testing with isolated Laravel test defaults';
                $output->writeln("<info>{$message}</info>");
            }
        }

        if ($usesMySql && $this->environmentFile->configurePhpUnitMySql($root)) {
            $output->writeln('<info>Configured PHPUnit to use the Sail testing database</info>');
        }

        return 0;
    }

    public function cleanup(string $root, OutputInterface $output): int
    {
        $config = $this->configLoader->load($root);
        $site = $this->state->herdSite($root);

        if ($site !== null) {
            $expected = SiteName::forPath($root);

            if (! hash_equals($expected, $site)) {
                throw new EnvironmentException("Refusing to unlink unexpected Herd site [{$site}]; expected [{$expected}].");
            }
        }

        $ownsMySql = $this->state->ownsMySqlDatabases($root)
            || ($site !== null && $this->usesMySql($config));

        if ($ownsMySql) {
            $mysqlCleanup = null;

            try {
                $mysqlCleanup = $this->commands->dropMySqlDatabases($root);
            } catch (EnvironmentException) {
                // Preserve ownership so a later cleanup can drop the databases after Sail is restored.
                $this->state->recordMySqlDatabases($root);
                $output->writeln('<comment>Skipping MySQL cleanup because Laravel Sail is unavailable.</comment>');
            }

            if ($mysqlCleanup !== null) {
                $output->writeln('<info>Dropping harness-owned MySQL databases</info>');
                $status = $this->processes->run($mysqlCleanup, $root, $output);

                if ($status !== 0) {
                    return $status;
                }

                if ($this->state->ownsMySqlDatabases($root)) {
                    $this->state->clearMySqlDatabases($root);
                }
            }
        }

        if ($site === null) {
            $output->writeln('<info>No harness-owned Herd site needs cleanup.</info>');

            return 0;
        }

        if ($this->state->herdSecured($root)) {
            $output->writeln("<info>Removing HTTPS from Herd site {$site}</info>");
            $status = $this->processes->run($this->commands->herd('unsecure', $site), $root, $output);

            if ($status !== 0) {
                return $status;
            }

            $this->state->clearHerdSecured($root);
        }

        $output->writeln("<info>Unlinking Herd site {$site}</info>");
        $status = $this->processes->run($this->commands->herd('unlink', $site), $root, $output);

        if ($status === 0) {
            $this->state->clearHerdSite($root);
        }

        return $status;
    }

    public function up(string $root, OutputInterface $output): int
    {
        $config = $this->configLoader->load($root);

        if (! $this->requiresSail($config)) {
            $output->writeln('<info>No Sail containers are configured.</info>');

            return 0;
        }

        return $this->processes->run($this->commands->servicesUp($config, $root), $root, $output);
    }

    public function down(string $root, OutputInterface $output): int
    {
        $config = $this->configLoader->load($root);

        if (! $this->requiresSail($config)) {
            $output->writeln('<info>No Sail containers are configured.</info>');

            return 0;
        }

        return $this->processes->run($this->commands->servicesDown($config, $root), $root, $output);
    }

    private function setupHerd(Config $config, string $root, OutputInterface $output): int
    {
        $site = SiteName::forPath($root);
        $ownedSite = $this->state->herdSite($root);

        if ($ownedSite !== null && ! hash_equals($site, $ownedSite)) {
            throw new EnvironmentException("Harness state owns unexpected Herd site [{$ownedSite}].");
        }

        if ($ownedSite === null) {
            $output->writeln("<info>Linking Herd site {$site}</info>");
            $status = $this->processes->run($this->commands->herd('link', $site, '--no-interaction'), $root, $output);

            if ($status !== 0) {
                return $status;
            }

            $this->state->recordHerdSite($root, $site);
        }

        if ($config->herdSecure) {
            if (! $this->state->herdSecured($root)) {
                $status = $this->processes->run($this->commands->herd('secure', $site), $root, $output);

                if ($status !== 0) {
                    return $status;
                }

                $this->state->recordHerdSecured($root);
            }
        } elseif ($this->state->herdSecured($root)) {
            $output->writeln("<info>Removing HTTPS from Herd site {$site}</info>");
            $status = $this->processes->run($this->commands->herd('unsecure', $site), $root, $output);

            if ($status !== 0) {
                return $status;
            }

            $this->state->clearHerdSecured($root);
        }

        if ($config->herdPhp !== null) {
            return $this->processes->run($this->commands->herd('isolate', $config->herdPhp), $root, $output);
        }

        return 0;
    }

    private function requiresSail(Config $config): bool
    {
        return $config->runtime === Runtime::Sail || $config->services === Services::Sail;
    }

    private function usesMySql(Config $config): bool
    {
        return $config->services === Services::Sail && in_array('mysql', $config->sailServices, true);
    }
}
