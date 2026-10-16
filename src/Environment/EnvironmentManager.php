<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Environment;

use MrKoopie\LaravelAiHarness\Config\Config;
use MrKoopie\LaravelAiHarness\Config\ConfigLoader;
use MrKoopie\LaravelAiHarness\Process\ProcessRunner;
use Symfony\Component\Console\Output\OutputInterface;

final readonly class EnvironmentManager
{
    /** Create the environment lifecycle coordinator. */
    public function __construct(
        private ConfigLoader $configLoader,
        private CommandFactory $commands,
        private ProcessRunner $processes,
        private EnvironmentFile $environmentFile,
        private StateStore $state,
        private ?CloudManager $cloud = null,
    ) {}

    /** Prepare dependencies, services, and checkout-specific configuration. */
    public function setup(string $root, OutputInterface $output): int
    {
        if (ExecutionEnvironment::current()->isCloud()) {
            return $this->cloudManager()->setup($root, $output);
        }

        $config = $this->configLoader->load($root);

        if ($config->existingDatabase !== null) {
            $conflicts = $this->environmentFile->existingDatabaseConflicts($root);
            if ($conflicts !== []) {
                throw new EnvironmentException('Existing-server database configuration conflicts with '.implode(', ', $conflicts).'; remove these conflicting overrides.');
            }
        }

        if ($config->localEnvironment !== []) {
            if (! is_file($root.'/.env') && ! is_file($root.'/.env.example')) {
                throw new EnvironmentException('Local environment overrides require .env or .env.example in the project root.');
            }
        }

        if ($config->localEnvironment !== [] || $config->managesMySql()) {
            $conflicts = $this->environmentFile->localOverrideConflicts($root, $config->localEnvironment, $config->managesMySql(), $config->runtime === Runtime::Sail, $config->existingDatabase !== null);

            if ($conflicts !== []) {
                throw new EnvironmentException('Local environment settings conflict with '.implode(', ', $conflicts).'; unset conflicting process variables or explicitly clear inherited URL/socket entries using local_env.<NAME>=.');
            }
        }

        if ($this->environmentFile->ensure($root)) {
            $output->writeln('<info>Created .env from .env.example</info>');
        }

        if ($config->localEnvironment !== []) {
            // Clear stale configuration before Artisan can read the changed endpoint.
            $this->environmentFile->clearCloudConfigCache($root);
            $this->environmentFile->applyLocalOverrides($root, $config->localEnvironment);
            $output->writeln('<info>Applied local environment overrides</info>');
        }

        $usesMySql = $this->usesMySql($config);

        if ($usesMySql) {
            $this->environmentFile->configureMySql($root, $config->runtime === Runtime::Sail);
            $output->writeln('<info>Configured MySQL environment values</info>');
        }

        if ($config->existingDatabase !== null) {
            $this->environmentFile->configureExistingMySql($root, $config->existingDatabase);
            (new ExistingMySql($this->state))->setup($root, $config->existingDatabase);
            $output->writeln('<info>Configured checkout-owned existing-server MySQL/MariaDB databases</info>');
        }

        if (! is_file($root.'/vendor/autoload.php')) {
            $output->writeln('<info>Installing Composer dependencies</info>');
            $status = $this->processes->run($this->commands->bootstrapComposer($config, $root), $root, $output);

            if ($status !== 0) {
                return $status;
            }
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

        $tool = SiteTool::forRuntime($config->runtime);

        if ($tool !== null) {
            $status = $this->setupSite($config, $tool, $root, $output);

            if ($status !== 0) {
                return $status;
            }

            $url = match ($tool) {
                SiteTool::Herd => ($config->siteSecure ? 'https' : 'http').'://'.SiteName::forPath($root).'.test',
                SiteTool::Valet => ($config->siteSecure ? 'https' : 'http').'://'.SiteName::forPath($root).'.'.ValetTld::current(),
                SiteTool::Yerd => (new Yerd)->url($this->commands->yerd('status')[0], $root, $config->siteSecure),
            };
            $this->environmentFile->setAppUrl($root, $url);
            $output->writeln("<info>Configured APP_URL for the {$tool->label()} site</info>");
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

    /** Remove only resources recorded as owned by the harness. */
    public function cleanup(string $root, OutputInterface $output): int
    {
        if (ExecutionEnvironment::current()->isCloud()) {
            return $this->cloudManager()->cleanup($root, $output);
        }

        $config = $this->configLoader->load($root);
        if ($this->state->existingDatabases($root) !== null) {
            if ($config->existingDatabase === null) {
                throw new EnvironmentException('Existing-server database ownership remains; restore its original MySQL/MariaDB connection settings in .env before cleanup.');
            }
            (new ExistingMySql($this->state))->cleanup($root, $config->existingDatabase);
            $output->writeln('<info>Dropped checkout-owned existing-server databases</info>');
        }
        $sites = [];

        foreach (SiteTool::cases() as $tool) {
            $site = $this->state->site($root, $tool);

            if ($site === null) {
                continue;
            }

            $expected = SiteName::forPath($root);

            if (! hash_equals($expected, $site)) {
                throw new EnvironmentException("Refusing to unlink unexpected {$tool->label()} site [{$site}]; expected [{$expected}].");
            }

            $sites[$tool->value] = $site;
        }

        $ownsMySql = $this->state->ownsMySqlDatabases($root)
            || ($sites !== [] && $this->usesMySql($config));

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

        if ($sites === []) {
            $output->writeln($config->runtime === Runtime::Yerd
                ? '<info>No harness-owned Yerd site needs cleanup.</info>'
                : '<info>No harness-owned Herd, Valet, or Yerd site needs cleanup.</info>');

            return 0;
        }

        foreach ($sites as $value => $site) {
            $status = $this->cleanupSite(SiteTool::from($value), $site, $root, $output);

            if ($status !== 0) {
                return $status;
            }
        }

        return 0;
    }

    /** Start the configured Sail services. */
    public function up(string $root, OutputInterface $output): int
    {
        if (ExecutionEnvironment::current()->isCloud()) {
            return $this->cloudManager()->setup($root, $output);
        }

        $config = $this->configLoader->load($root);

        if (! $this->requiresSail($config)) {
            $output->writeln('<info>No Sail containers are configured.</info>');

            return 0;
        }

        return $this->processes->run($this->commands->servicesUp($config, $root), $root, $output);
    }

    /** Stop the configured Sail services without deleting volumes. */
    public function down(string $root, OutputInterface $output): int
    {
        if (ExecutionEnvironment::current()->isCloud()) {
            $output->writeln('<info>Cloud services stay available for resumed sessions.</info>');

            return 0;
        }

        $config = $this->configLoader->load($root);

        if (! $this->requiresSail($config)) {
            $output->writeln('<info>No Sail containers are configured.</info>');

            return 0;
        }

        return $this->processes->run($this->commands->servicesDown($config, $root), $root, $output);
    }

    /** Link, secure, and optionally isolate the project's Herd, Valet, or Yerd site. */
    private function setupSite(Config $config, SiteTool $tool, string $root, OutputInterface $output): int
    {
        $site = SiteName::forPath($root);
        $ownedSite = $this->state->site($root, $tool);

        if ($ownedSite !== null && ! hash_equals($site, $ownedSite)) {
            throw new EnvironmentException("Harness state owns unexpected {$tool->label()} site [{$ownedSite}].");
        }

        if ($tool === SiteTool::Yerd) {
            $existing = (new Yerd)->site($this->commands->yerd('sites')[0], $root, $site);
            if ($existing !== null && ($ownedSite === null || ! Yerd::belongsTo($existing, $root))) {
                throw new EnvironmentException("Refusing to modify an unowned or reassigned Yerd site [{$site}].");
            }
            if ($existing === null) {
                $ownedSite = null;
                $this->state->clearSiteSecured($root, $tool);
            }
        }

        if ($ownedSite === null) {
            $output->writeln("<info>Linking {$tool->label()} site {$site}</info>");
            $arguments = match ($tool) {
                SiteTool::Herd => [$site, '--no-interaction'],
                SiteTool::Valet => [$site],
                SiteTool::Yerd => [$site, $root],
            };
            $status = $this->processes->run($this->siteCommand($tool, 'link', ...$arguments), $root, $output);

            if ($status !== 0) {
                return $status;
            }

            $this->state->recordSite($root, $tool, $site);
        }

        if ($tool === SiteTool::Yerd && is_file($root.'/artisan')) {
            foreach ([['root', $site, 'public'], ['front-controller', $site, 'on']] as $arguments) {
                $status = $this->processes->run($this->commands->yerd(...$arguments), $root, $output);
                if ($status !== 0) {
                    return $status;
                }
            }
        }

        if ($config->siteSecure) {
            if (! $this->state->siteSecured($root, $tool)) {
                $status = $this->processes->run($this->siteCommand($tool, 'secure', $site), $root, $output);

                if ($status !== 0) {
                    return $status;
                }

                $this->state->recordSiteSecured($root, $tool);
            }
        } elseif ($this->state->siteSecured($root, $tool)) {
            $output->writeln("<info>Removing HTTPS from {$tool->label()} site {$site}</info>");
            $status = $this->processes->run($this->siteCommand($tool, 'unsecure', $site), $root, $output);

            if ($status !== 0) {
                return $status;
            }

            $this->state->clearSiteSecured($root, $tool);
        }

        $php = $config->phpVersion;

        if ($php === null) {
            return 0;
        }

        // Valet isolates the directory name by default, which differs from the checkout-specific site name.
        $command = match ($tool) {
            SiteTool::Herd => $this->siteCommand($tool, 'isolate', $php),
            SiteTool::Valet => $this->siteCommand($tool, 'isolate', 'php@'.$php, '--site='.$site),
            SiteTool::Yerd => $this->siteCommand($tool, 'use', $site, $php),
        };

        return $this->processes->run($command, $root, $output);
    }

    /** Remove HTTPS from and unlink one harness-owned site. */
    private function cleanupSite(SiteTool $tool, string $site, string $root, OutputInterface $output): int
    {
        if ($tool === SiteTool::Yerd) {
            $existing = (new Yerd)->site($this->commands->yerd('sites')[0], $root, $site);
            if ($existing === null) {
                $this->state->clearSite($root, $tool);

                return 0;
            }
            if (! Yerd::belongsTo($existing, $root)) {
                throw new EnvironmentException("Refusing to unlink reassigned Yerd site [{$site}].");
            }
        }

        if ($this->state->siteSecured($root, $tool)) {
            $output->writeln("<info>Removing HTTPS from {$tool->label()} site {$site}</info>");
            $status = $this->processes->run($this->siteCommand($tool, 'unsecure', $site), $root, $output);

            if ($status !== 0) {
                return $status;
            }

            $this->state->clearSiteSecured($root, $tool);
        }

        if ($tool === SiteTool::Yerd) {
            // TLS removal is a separate daemon call; do not trust the earlier target check.
            $current = (new Yerd)->site($this->commands->yerd('sites')[0], $root, $site);
            if ($current === null) {
                $this->state->clearSite($root, $tool);

                return 0;
            }
            if (! Yerd::belongsTo($current, $root)) {
                throw new EnvironmentException("Refusing to unlink reassigned Yerd site [{$site}].");
            }
        }

        $output->writeln("<info>Unlinking {$tool->label()} site {$site}</info>");
        $status = $this->processes->run($this->siteCommand($tool, 'unlink', $site), $root, $output);

        if ($status === 0) {
            $this->state->clearSite($root, $tool);
        }

        return $status;
    }

    /**
     * Build a Herd, Valet, or Yerd command.
     *
     * @return non-empty-list<string>
     */
    private function siteCommand(SiteTool $tool, string $action, string ...$arguments): array
    {
        return match ($tool) {
            SiteTool::Herd => $this->commands->herd($action, ...$arguments),
            SiteTool::Valet => $this->commands->valet($action, ...$arguments),
            SiteTool::Yerd => $this->commands->yerd($action, ...$arguments),
        };
    }

    /** Determine whether the configuration needs Sail containers. */
    private function requiresSail(Config $config): bool
    {
        return $config->runtime === Runtime::Sail || $config->serviceManager === Services::Sail;
    }

    /** Determine whether the configured Sail services include MySQL. */
    private function usesMySql(Config $config): bool
    {
        return $config->managesMySql();
    }

    /** Resolve the cloud coordinator for lifecycle commands. */
    private function cloudManager(): CloudManager
    {
        return $this->cloud ?? new CloudManager($this->configLoader, $this->commands, $this->processes, $this->environmentFile, $this->state);
    }
}
