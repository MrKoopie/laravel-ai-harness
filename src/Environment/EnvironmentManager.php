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

        if ($this->requiresSail($config)) {
            $output->writeln('<info>Starting configured Sail containers</info>');
            $status = $this->processes->run($this->commands->servicesUp($config, $root), $root, $output);

            if ($status !== 0) {
                return $status;
            }
        }

        if ($config->runtime === Runtime::Herd) {
            $status = $this->setupHerd($config, $root, $output);

            if ($status !== 0) {
                return $status;
            }
        }

        if (is_file($root.'/artisan') && $this->environmentFile->appKeyMissing($root)) {
            $output->writeln('<info>Generating Laravel application key</info>');

            return $this->processes->run(
                $this->commands->runtime($config, 'artisan', ['key:generate', '--ansi'], $root),
                $root,
                $output,
            );
        }

        return 0;
    }

    public function cleanup(string $root, OutputInterface $output): int
    {
        $site = $this->state->herdSite($root);

        if ($site === null) {
            $output->writeln('<info>No harness-owned resources need cleanup.</info>');

            return 0;
        }

        $expected = SiteName::forPath($root);

        if (! hash_equals($expected, $site)) {
            throw new EnvironmentException("Refusing to unlink unexpected Herd site [{$site}]; expected [{$expected}].");
        }

        if ($this->state->herdSecured($root)) {
            $output->writeln("<info>Removing HTTPS from Herd site {$site}</info>");
            $status = $this->processes->run($this->commands->herd('unsecure', $root, $site), $root, $output);

            if ($status !== 0) {
                return $status;
            }
        }

        $output->writeln("<info>Unlinking Herd site {$site}</info>");
        $status = $this->processes->run($this->commands->herd('unlink', $root, $site), $root, $output);

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
            $status = $this->processes->run($this->commands->herd('link', $root, $site, '--no-interaction'), $root, $output);

            if ($status !== 0) {
                return $status;
            }

            $this->state->recordHerdSite($root, $site);
        }

        if ($config->herdSecure) {
            $status = $this->processes->run($this->commands->herd('secure', $root, $site), $root, $output);

            if ($status !== 0) {
                return $status;
            }

            $this->state->recordHerdSecured($root);
        } elseif ($this->state->herdSecured($root)) {
            $output->writeln("<info>Removing HTTPS from Herd site {$site}</info>");
            $status = $this->processes->run($this->commands->herd('unsecure', $root, $site), $root, $output);

            if ($status !== 0) {
                return $status;
            }

            $this->state->clearHerdSecured($root);
        }

        if ($config->herdPhp !== null) {
            return $this->processes->run($this->commands->herd('isolate', $root, $config->herdPhp), $root, $output);
        }

        return 0;
    }

    private function requiresSail(Config $config): bool
    {
        return $config->runtime === Runtime::Sail || $config->services === Services::Sail;
    }
}
