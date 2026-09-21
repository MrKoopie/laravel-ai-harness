<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Environment;

use MrKoopie\LaravelAiHarness\Config\Config;
use MrKoopie\LaravelAiHarness\Config\ConfigLoader;
use MrKoopie\LaravelAiHarness\Files\SafeWriter;
use MrKoopie\LaravelAiHarness\Process\ProcessRunner;
use Symfony\Component\Console\Output\OutputInterface;

final readonly class CloudManager
{
    /** Compose cloud preparation from the existing file and process helpers. */
    public function __construct(
        private ConfigLoader $configLoader,
        private CommandFactory $commands,
        private ProcessRunner $processes,
        private EnvironmentFile $files,
        private StateStore $state,
    ) {}

    /** Reconcile the checked-out branch and restart services on fresh and cached starts. */
    public function setup(string $root, OutputInterface $output): int
    {
        $config = $this->configuration($root);

        if (! $config->cloud) {
            $output->writeln('<info>Cloud automation is disabled.</info>');

            return 0;
        }

        if (! is_file($root.'/composer.lock')) {
            throw new EnvironmentException('Cloud setup requires a committed composer.lock.');
        }

        $output->writeln('<info>Preparing '.ExecutionEnvironment::current()->value.' with native PHP</info>');
        $this->state->ownsCloudTesting($root);
        $this->files->ensure($root);

        $mysql = in_array('mysql', $config->cloudServices, true);
        $redis = in_array('redis', $config->cloudServices, true);

        if ($redis && ! extension_loaded('redis')) {
            throw new EnvironmentException('Cloud Redis requires the phpredis extension; run cloud provision for this PHP version.');
        }

        // Prepare safe local configuration before Composer invokes Laravel scripts.
        if (is_file($root.'/artisan')) {
            if (! is_file($root.'/.env')) {
                throw new EnvironmentException('Laravel cloud setup requires .env or .env.example.');
            }

            $this->files->configureCloud($root, $mysql, $redis);
            $this->files->clearCloudConfigCache($root);

            if (! $mysql) {
                $writer = new SafeWriter;
                $writer->assertSafePath($root, 'database/database.sqlite');

                if (is_link($root.'/database/database.sqlite')) {
                    throw new EnvironmentException('Refusing a symbolic link for the cloud SQLite database.');
                }

                if (! is_file($root.'/database/database.sqlite')) {
                    $writer->write($root, 'database/database.sqlite', '');
                }
            }
        }

        foreach ($config->cloudServices as $service) {
            $status = $this->script('service', [$service], $root, $output);

            if ($status !== 0) {
                return $status;
            }
        }

        if ($mysql) {
            $status = $this->database('setup', $root, $output);

            if ($status !== 0) {
                return $status;
            }

            $this->state->recordCloudTesting($root);
        }

        $preference = getenv('AI_HARNESS_COMPOSER_PREFER') ?: 'dist';

        if (! in_array($preference, ['dist', 'source'], true)) {
            throw new EnvironmentException('AI_HARNESS_COMPOSER_PREFER must be dist or source.');
        }

        foreach ([['install', '--no-interaction', '--prefer-'.$preference], ['check-platform-reqs']] as $arguments) {
            $status = $this->tool($config, 'composer', $arguments, $root, $output);

            if ($status !== 0) {
                return $status;
            }
        }

        if (is_file($root.'/artisan')) {
            $status = $this->tool($config, 'artisan', ['config:clear', '--no-interaction'], $root, $output);

            if ($status !== 0) {
                return $status;
            }

            if ($this->files->appKeyMissing($root)) {
                $status = $this->tool($config, 'artisan', ['key:generate', '--no-interaction'], $root, $output);

                if ($status !== 0) {
                    return $status;
                }
            }

            $this->files->syncTestingKey($root);

            $this->files->configureCloudPhpUnit($root);

            if ($config->cloudMigrate) {
                foreach ([[], ['--env=testing']] as $environment) {
                    $status = $this->tool($config, 'artisan', ['migrate', '--force', '--no-interaction', ...$environment], $root, $output);

                    if ($status !== 0) {
                        return $status;
                    }
                }
            }

            if ($config->cloudSeed) {
                $status = $this->tool($config, 'artisan', ['db:seed', '--force', '--no-interaction'], $root, $output);

                if ($status !== 0) {
                    return $status;
                }
            }
        }

        if (is_file($root.'/package.json')) {
            if (! is_file($root.'/package-lock.json')) {
                throw new EnvironmentException('Cloud frontend setup currently requires package-lock.json (npm ci).');
            }

            $status = $this->tool($config, 'npm', ['ci', '--include=dev'], $root, $output);

            if ($status !== 0) {
                return $status;
            }

            if ($config->cloudBuild) {
                $status = $this->tool($config, 'npm', ['run', 'build'], $root, $output);

                if ($status !== 0) {
                    return $status;
                }
            }

            if ($config->cloudBrowser) {
                $playwright = $root.'/node_modules/.bin/playwright';

                if (! is_executable($playwright)) {
                    throw new EnvironmentException('cloud_browser requires Playwright in the project dependencies.');
                }

                return $this->processes->run([$playwright, 'install', '--with-deps', 'chromium'], $root, $output);
            }
        }

        return 0;
    }

    /** Drop only the exact owned testing schema and its numeric worker schemas. */
    public function cleanup(string $root, OutputInterface $output): int
    {
        $config = $this->configuration($root);

        if (! $config->cloud || ! $this->state->ownsCloudTesting($root)) {
            $output->writeln('<info>No owned cloud testing databases need cleanup.</info>');

            return 0;
        }

        $output->writeln('<info>Cleaning cloud testing databases; preserving development data</info>');

        return $this->database('cleanup', $root, $output);
    }

    /** Require an explicit cloud signal before any lifecycle mutation. */
    private function configuration(string $root): Config
    {
        if (! ExecutionEnvironment::current()->isCloud()) {
            throw new EnvironmentException('This action requires a cloud environment: set AI_HARNESS_ENV=codex-cloud or claude-cloud.');
        }

        return $this->configLoader->load($root);
    }

    /** Run the bounded socket-only database adapter. */
    private function database(string $action, string $root, OutputInterface $output): int
    {
        return $this->script('mysql', [
            $action,
            DatabaseName::forPath($root),
            DatabaseName::testingForPath($root),
            'harness_'.substr(hash('sha256', $root), 0, 10),
        ], $root, $output);
    }

    /** @param list<string> $arguments */
    private function script(string $name, array $arguments, string $root, OutputInterface $output): int
    {
        return $this->processes->run(['bash', dirname(__DIR__, 2).'/resources/cloud/'.$name.'.sh', ...$arguments], $root, $output);
    }

    /** @param list<string> $arguments */
    private function tool(Config $config, string $tool, array $arguments, string $root, OutputInterface $output): int
    {
        return $this->processes->run($this->commands->runtime($config, $tool, $arguments, $root), $root, $output, ExecutionEnvironment::processEnvironment());
    }
}
