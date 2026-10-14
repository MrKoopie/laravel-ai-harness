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

        if ($config->localEnvironment !== []) {
            if (! is_file($root.'/.env') && ! is_file($root.'/.env.example')) {
                throw new EnvironmentException('Local environment overrides require .env or .env.example in the project root.');
            }
        }

        if ($config->localEnvironment !== [] || $config->managesMySql()) {
            $conflicts = $this->environmentFile->localOverrideConflicts($root, $config->localEnvironment, $config->managesMySql(), $config->runtime === Runtime::Sail);

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

        if (! is_file($root.'/vendor/autoload.php')) {
            $output->writeln('<info>Installing Composer dependencies</info>');
            $status = $this->processes->run($this->commands->bootstrapComposer(), $root, $output);

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

            $url = $tool === SiteTool::Herd
                ? 'https://'.SiteName::forPath($root).'.test'
                : ($config->valetSecure ? 'https' : 'http').'://'.SiteName::forPath($root).'.'.ValetTld::current();
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
            $output->writeln('<info>No harness-owned Herd or Valet site needs cleanup.</info>');

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

    /** Link, secure, and optionally isolate the project's Herd or Valet site. */
    private function setupSite(Config $config, SiteTool $tool, string $root, OutputInterface $output): int
    {
        $site = SiteName::forPath($root);
        $ownedSite = $this->state->site($root, $tool);

        if ($ownedSite !== null && ! hash_equals($site, $ownedSite)) {
            throw new EnvironmentException("Harness state owns unexpected {$tool->label()} site [{$ownedSite}].");
        }

        if ($ownedSite === null) {
            $output->writeln("<info>Linking {$tool->label()} site {$site}</info>");
            $arguments = $tool === SiteTool::Herd ? [$site, '--no-interaction'] : [$site];
            $status = $this->processes->run($this->siteCommand($tool, 'link', ...$arguments), $root, $output);

            if ($status !== 0) {
                return $status;
            }

            $this->state->recordSite($root, $tool, $site);
        }

        if ($this->secure($config, $tool)) {
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

        $php = $tool === SiteTool::Herd ? $config->herdPhp : $config->valetPhp;

        if ($php === null) {
            return 0;
        }

        // Valet isolates the directory name by default, which differs from the checkout-specific site name.
        $command = $tool === SiteTool::Herd
            ? $this->siteCommand($tool, 'isolate', $php)
            : $this->siteCommand($tool, 'isolate', 'php@'.$php, '--site='.$site);

        return $this->processes->run($command, $root, $output);
    }

    /** Remove HTTPS from and unlink one harness-owned site. */
    private function cleanupSite(SiteTool $tool, string $site, string $root, OutputInterface $output): int
    {
        if ($this->state->siteSecured($root, $tool)) {
            $output->writeln("<info>Removing HTTPS from {$tool->label()} site {$site}</info>");
            $status = $this->processes->run($this->siteCommand($tool, 'unsecure', $site), $root, $output);

            if ($status !== 0) {
                return $status;
            }

            $this->state->clearSiteSecured($root, $tool);
        }

        $output->writeln("<info>Unlinking {$tool->label()} site {$site}</info>");
        $status = $this->processes->run($this->siteCommand($tool, 'unlink', $site), $root, $output);

        if ($status === 0) {
            $this->state->clearSite($root, $tool);
        }

        return $status;
    }

    /**
     * Build a Herd or Valet command.
     *
     * @return non-empty-list<string>
     */
    private function siteCommand(SiteTool $tool, string $action, string ...$arguments): array
    {
        return match ($tool) {
            SiteTool::Herd => $this->commands->herd($action, ...$arguments),
            SiteTool::Valet => $this->commands->valet($action, ...$arguments),
        };
    }

    /** Determine whether the configured site must use HTTPS. */
    private function secure(Config $config, SiteTool $tool): bool
    {
        return $tool === SiteTool::Herd ? $config->herdSecure : $config->valetSecure;
    }

    /** Determine whether the configuration needs Sail containers. */
    private function requiresSail(Config $config): bool
    {
        return $config->runtime === Runtime::Sail || $config->services === Services::Sail;
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
