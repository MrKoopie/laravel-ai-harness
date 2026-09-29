<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Environment;

use MrKoopie\LaravelAiHarness\Config\Config;
use MrKoopie\LaravelAiHarness\Process\ExecutableLocator;

final readonly class CommandFactory
{
    /** Create a command factory backed by executable discovery. */
    public function __construct(private ExecutableLocator $executables) {}

    /**
     * Build a command for a tool in the configured runtime.
     *
     * @param  list<string>  $arguments
     * @return non-empty-list<string>
     */
    public function runtime(Config $config, string $tool, array $arguments, string $root): array
    {
        $runtime = ExecutionEnvironment::current()->isCloud() ? Runtime::Native : $config->runtime;
        $prefix = match ($runtime) {
            Runtime::Native => $this->nativePrefix($tool, $root),
            Runtime::Herd => $this->herdPrefix($tool, $root, str_starts_with($arguments[0] ?? '', '--site=')),
            Runtime::Sail => $this->sailPrefix($tool, $root),
        };

        return array_merge($prefix, $arguments);
    }

    /**
     * Build the command used to install missing Composer dependencies.
     *
     * @return non-empty-list<string>
     */
    public function bootstrapComposer(): array
    {
        $composer = $this->executables->composer();

        if ($composer !== null) {
            return [$composer, 'install', '--no-interaction', '--prefer-dist'];
        }

        $herd = $this->executables->herd();

        if ($herd !== null) {
            return [$herd, 'composer', 'install', '--no-interaction', '--prefer-dist'];
        }

        throw new EnvironmentException('Composer dependencies are missing and neither Composer nor Laravel Herd is available to install them.');
    }

    /**
     * Build the command that starts configured Sail services.
     *
     * @return non-empty-list<string>
     */
    public function servicesUp(Config $config, string $root): array
    {
        $command = [$this->sail($root), 'up', '-d'];

        return array_merge($command, $this->sailTargets($config));
    }

    /**
     * Build the command that stops configured Sail services.
     *
     * @return non-empty-list<string>
     */
    public function servicesDown(Config $config, string $root): array
    {
        if ($config->services === Services::Sail && $config->sailServices === []) {
            return [$this->sail($root), 'down'];
        }

        return array_merge([$this->sail($root), 'stop'], $this->sailTargets($config));
    }

    /**
     * Build the Sail command that creates checkout-specific MySQL databases.
     *
     * @return non-empty-list<string>
     */
    public function ensureMySqlDatabases(string $root): array
    {
        $database = DatabaseName::forPath($root);
        $testing = DatabaseName::testingForPath($root);
        $databaseGrant = str_replace('_', '\\_', $database);
        $testingGrant = str_replace('_', '\\_', $testing);
        $statement = sprintf(
            'for attempt in {1..30}; do MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqladmin --user=root ping --silent && break; sleep 1; done; MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql --user=root --execute=\'CREATE DATABASE IF NOT EXISTS `%s`; CREATE DATABASE IF NOT EXISTS `%s`; GRANT ALL PRIVILEGES ON `%s`.* TO `sail`@`%%`; GRANT ALL PRIVILEGES ON `%s`.* TO `sail`@`%%`; GRANT ALL PRIVILEGES ON `%s\\_%%`.* TO `sail`@`%%`;\'',
            $database,
            $testing,
            $databaseGrant,
            $testingGrant,
            $testingGrant,
        );

        return [$this->sail($root), 'exec', '-T', 'mysql', 'bash', '-c', $statement];
    }

    /**
     * Build the Sail command that drops checkout-specific MySQL databases.
     *
     * @return non-empty-list<string>
     */
    public function dropMySqlDatabases(string $root): array
    {
        $database = DatabaseName::forPath($root);
        $testing = DatabaseName::testingForPath($root);
        $legacyDatabase = DatabaseName::legacyForPath($root);
        $legacyTesting = $legacyDatabase.'_testing';
        $names = array_values(array_unique([$database, $testing, $legacyDatabase, $legacyTesting]));
        $drops = implode(' ', array_map(static fn (string $name): string => "DROP DATABASE IF EXISTS `{$name}`;", $names));
        $testingNames = array_values(array_unique([$testing, $legacyTesting]));
        $workerPattern = '^('.implode('|', $testingNames).')(_test)?_[0-9]+$';
        $statement = sprintf(
            'set -eo pipefail; for attempt in {1..30}; do MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqladmin --user=root ping --silent && break; sleep 1; done; MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql --user=root --execute=\'%s\'; MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql --user=root --batch --skip-column-names --execute=\'SHOW DATABASES;\' | while IFS= read -r name; do if [[ "$name" =~ %s ]]; then MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql --user=root --execute="DROP DATABASE IF EXISTS \`$name\`;"; fi; done',
            $drops,
            $workerPattern,
        );

        return [$this->sail($root), 'exec', '-T', 'mysql', 'bash', '-c', $statement];
    }

    /**
     * Build a Laravel Herd command.
     *
     * @return non-empty-list<string>
     */
    public function herd(string $action, string ...$arguments): array
    {
        $herd = $this->executables->herd();

        if ($herd === null) {
            throw new EnvironmentException('Laravel Herd is configured but its executable cannot be found.');
        }

        return [$herd, $action, ...array_values($arguments)];
    }

    /**
     * Build the executable prefix for a native runtime tool.
     *
     * @return non-empty-list<string>
     */
    private function nativePrefix(string $tool, string $root): array
    {
        return match ($tool) {
            'artisan' => [$this->required($this->executables->php(), 'PHP'), $root.'/artisan'],
            'composer' => [$this->required($this->executables->composer(), 'Composer')],
            'php' => [$this->required($this->executables->php(), 'PHP')],
            'test' => [$this->required($this->executables->php(), 'PHP'), $root.'/artisan', 'test'],
            'npm' => [$this->required($this->executables->find('npm'), 'npm')],
            default => throw new EnvironmentException("Unknown runtime tool [{$tool}]."),
        };
    }

    /**
     * Build the executable prefix for a Herd runtime tool.
     *
     * @return non-empty-list<string>
     */
    private function herdPrefix(string $tool, string $root, bool $explicitSite = false): array
    {
        $herd = $this->required($this->executables->herd(), 'Laravel Herd');
        $php = ! $explicitSite && in_array($tool, ['php', 'artisan', 'test', 'composer'], true)
            ? (new HerdPhp)->resolve($herd, $root)
            : null;

        if ($php !== null) {
            $composer = $this->executables->composer();

            return match ($tool) {
                'artisan' => [$php, $root.'/artisan'],
                'test' => [$php, $root.'/artisan', 'test'],
                'composer' => $composer !== null ? [$php, $composer] : [$herd, 'composer'],
                default => [$php],
            };
        }

        return match ($tool) {
            'artisan' => [$herd, 'php', $root.'/artisan'],
            'composer' => [$herd, 'composer'],
            'php' => [$herd, 'php'],
            'test' => [$herd, 'php', $root.'/artisan', 'test'],
            'npm' => [$this->required($this->executables->find('npm'), 'npm')],
            default => throw new EnvironmentException("Unknown runtime tool [{$tool}]."),
        };
    }

    /**
     * Build the executable prefix for a Sail runtime tool.
     *
     * @return non-empty-list<string>
     */
    private function sailPrefix(string $tool, string $root): array
    {
        $sail = $this->sail($root);

        return match ($tool) {
            'artisan' => [$sail, 'artisan'],
            'composer' => [$sail, 'composer'],
            'php' => [$sail, 'php'],
            'test' => [$sail, 'artisan', 'test'],
            'npm' => [$sail, 'npm'],
            default => throw new EnvironmentException("Unknown runtime tool [{$tool}]."),
        };
    }

    /** Resolve the executable Sail script for a project. */
    private function sail(string $root): string
    {
        $path = $root.'/vendor/bin/sail';

        if (! is_executable($path)) {
            throw new EnvironmentException('Laravel Sail is configured but vendor/bin/sail is missing or not executable.');
        }

        return $path;
    }

    /**
     * Select the Sail service targets required by the configuration.
     *
     * @return list<string>
     */
    private function sailTargets(Config $config): array
    {
        if ($config->services === Services::Sail && $config->sailServices === []) {
            return [];
        }

        $targets = $config->runtime === Runtime::Sail ? ['laravel.test'] : [];

        if ($config->services === Services::Sail) {
            $targets = [...$targets, ...$config->sailServices];
        }

        return array_values(array_unique($targets));
    }

    /** Return a required executable path or report that it is missing. */
    private function required(?string $path, string $name): string
    {
        if ($path === null) {
            throw new EnvironmentException("{$name} is required but its executable cannot be found.");
        }

        return $path;
    }
}
