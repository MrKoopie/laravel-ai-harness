<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Console;

use MrKoopie\LaravelAiHarness\Config\ConfigException;
use MrKoopie\LaravelAiHarness\Config\ConfigLoader;
use MrKoopie\LaravelAiHarness\Files\ClaudeSettings;
use MrKoopie\LaravelAiHarness\Files\CodexConfiguration;
use MrKoopie\LaravelAiHarness\Files\GitExcludes;
use MrKoopie\LaravelAiHarness\Files\ProjectSynchronizer;
use MrKoopie\LaravelAiHarness\Files\SafeWriter;
use MrKoopie\LaravelAiHarness\Support\GlobalInstallation;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'init', description: 'Install or refresh the small project-side harness files')]
final class InitCommand extends ProjectCommand
{
    /** Every scalar harness key has a named option; --database remains a friendly alias.
     * @var array<string, string>
     */
    private const OPTIONS = [
        'runtime' => 'runtime',
        'agents' => 'agents', 'sail-services' => 'sail_services',
        'site-secure' => 'site_secure', 'php-version' => 'php_version',
        'database-provision-username' => 'database_provision_username',
        'database-provision-password' => 'database_provision_password',
        'database-parallel-processes' => 'database_parallel_processes',
        'database' => 'db_connection', 'db-connection' => 'db_connection',
        'worktrees' => 'worktrees', 'cloud' => 'cloud',
        'cloud-services' => 'cloud_services', 'cloud-migrate' => 'cloud_migrate',
        'cloud-seed' => 'cloud_seed', 'cloud-build' => 'cloud_build', 'cloud-browser' => 'cloud_browser',
    ];

    /** Create the project integration installer command. */
    public function __construct(private readonly ProjectSynchronizer $synchronizer)
    {
        parent::__construct();
    }

    /** Configure the shared project path option. */
    protected function configure(): void
    {
        $this->configureProjectPath();
        $descriptions = [
            'runtime' => 'PHP runtime: native, herd, valet, yerd, or sail',
            'agents' => 'Comma-separated coding agents: codex,claude (empty disables integrations)',
            'php-version' => 'Installed site PHP version, for example 8.5 (empty preserves selection)',
            'site-secure' => 'Serve the checkout site over HTTPS: true or false',
            'database' => 'Database selection: auto, sqlite, mysql, or mariadb',
            'database-provision-username' => 'Administrative database account (empty uses the application account)',
            'database-provision-password' => 'Administrative database password',
            'database-parallel-processes' => 'Number of parallel testing databases to provision: 0 through 64',
            'db-connection' => 'Alias for --database',
            'sail-services' => 'Comma-separated Sail services (empty: full stack for Sail PHP, no containers for host PHP)',
            'cloud-services' => 'Comma-separated cloud services: mysql,redis (empty uses SQLite)',
        ];
        foreach (self::OPTIONS as $option => $key) {
            $this->addOption($option, null, InputOption::VALUE_REQUIRED, $descriptions[$option] ?? "Set {$key}: true or false");
        }
        $this->addOption('agent', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Select an agent; repeat for multiple agents (alias for --agents)');
        $this->addOption('local-env', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Local application override NAME=VALUE; repeat for multiple variables, NAME= clears it');
        $this->addOption('migrate', null, InputOption::VALUE_NONE, 'Run migrations in generated worktree setup');
        $this->addOption('build', null, InputOption::VALUE_NONE, 'Install npm dependencies and build in generated worktree setup');
        $this->addOption('global', null, InputOption::VALUE_NONE, 'Use the global Composer command and a Git-ignored personal configuration');
    }

    /** Install or refresh the project-side harness files. */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $root = $this->projectPath($input);
        $overrides = $this->overrides($input);
        $migrate = (bool) $input->getOption('migrate');
        $build = (bool) $input->getOption('build');
        $configured = $overrides !== [] || $migrate || $build;
        $config = (new ConfigLoader)->load($root, $overrides);
        $codexEnabled = $configured && $config->supportsAgent('codex');
        if (($migrate || $build) && (! $codexEnabled || ! $config->worktrees)) {
            throw new ConfigException('--migrate and --build require a selected Codex agent with worktrees=true.');
        }
        $codex = new CodexConfiguration;
        $variables = $config->environment();
        $shellConfig = $codexEnabled ? $codex->shellConfig($root, $variables) : '';
        $global = (bool) $input->getOption('global') || GlobalInstallation::active($root);
        $claude = new ClaudeSettings(new SafeWriter);
        if ($configured && $config->supportsAgent('claude')) {
            // Preflight before writing project integrations.
            $claude->environment($root, $variables, $global, write: false);
        }
        if ($codexEnabled && $global) {
            (new GitExcludes(new SafeWriter))->sync($root, ['.codex/config.toml']);
        }
        $result = $this->synchronizer->sync($root, $global, $overrides);
        if ($codexEnabled) {
            $codex->write($root, $config, $global, $migrate, $build, $shellConfig);
            $output->writeln('<info>Configured Codex command settings'.($config->worktrees ? ', setup, and cleanup. Select this environment in Codex before creating worktrees.' : '. Worktree automation is disabled.').'</info>');
        }
        if ($configured && $config->supportsAgent('claude')) {
            $output->writeln('<info>Configured Claude command settings.</info>');
        }

        if ($result->createdConfig) {
            $output->writeln("<info>created {$result->configFilename}</info>");
        }

        foreach ($result->written as $path) {
            $output->writeln("<info>updated {$path}</info>");
        }

        $output->writeln('<info>AI Harness initialized.</info>');

        return self::SUCCESS;
    }

    /** @return array<string, string> */
    private function overrides(InputInterface $input): array
    {
        $overrides = [];
        foreach (self::OPTIONS as $option => $key) {
            $value = $input->getOption($option);
            if (is_string($value)) {
                if (array_key_exists($key, $overrides)) {
                    throw new ConfigException("Specify only one option for {$key}.");
                }
                $overrides[$key] = $value;
            }
        }
        $agents = $input->getOption('agent');
        if (is_array($agents) && $agents !== []) {
            if (array_key_exists('agents', $overrides)) {
                throw new ConfigException('Use either --agents or repeated --agent options, not both.');
            }
            $overrides['agents'] = implode(',', $agents);
        }
        $local = $input->getOption('local-env');
        if (is_array($local)) {
            foreach ($local as $assignment) {
                if (! is_string($assignment) || preg_match('/^([A-Z_][A-Z0-9_]*)=(.*)$/sD', $assignment, $match) !== 1) {
                    throw new ConfigException('--local-env requires an uppercase NAME=VALUE assignment.');
                }
                $key = 'local_env.'.$match[1];
                if (array_key_exists($key, $overrides)) {
                    throw new ConfigException("Duplicate --local-env variable [{$match[1]}].");
                }
                $overrides[$key] = $match[2];
            }
        }

        return $overrides;
    }
}
