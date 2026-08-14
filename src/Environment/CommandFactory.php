<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Environment;

use MrKoopie\LaravelAiHarness\Config\Config;
use MrKoopie\LaravelAiHarness\Process\ExecutableLocator;

final readonly class CommandFactory
{
    public function __construct(private ExecutableLocator $executables) {}

    /**
     * @param  list<string>  $arguments
     * @return non-empty-list<string>
     */
    public function runtime(Config $config, string $tool, array $arguments, string $root): array
    {
        $prefix = match ($config->runtime) {
            Runtime::Native => $this->nativePrefix($tool, $root),
            Runtime::Herd => $this->herdPrefix($tool, $root),
            Runtime::Sail => $this->sailPrefix($tool, $root),
        };

        return array_merge($prefix, $arguments);
    }

    /** @return non-empty-list<string> */
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

    /** @return non-empty-list<string> */
    public function servicesUp(Config $config, string $root): array
    {
        $command = [$this->sail($root), 'up', '-d'];

        return array_merge($command, $this->sailTargets($config));
    }

    /** @return non-empty-list<string> */
    public function servicesDown(Config $config, string $root): array
    {
        if ($config->services === Services::Sail && $config->sailServices === []) {
            return [$this->sail($root), 'down'];
        }

        return array_merge([$this->sail($root), 'stop'], $this->sailTargets($config));
    }

    /** @return non-empty-list<string> */
    public function ensureMySqlDatabases(string $root): array
    {
        $database = DatabaseName::forPath($root);
        $testing = DatabaseName::testingForPath($root);
        $statement = sprintf(
            'for attempt in {1..30}; do MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqladmin --user=root ping --silent && break; sleep 1; done; MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql --user=root --execute=\'CREATE DATABASE IF NOT EXISTS `%s`; CREATE DATABASE IF NOT EXISTS `%s`; GRANT ALL PRIVILEGES ON `%s`.* TO `sail`@`%%`; GRANT ALL PRIVILEGES ON `%s`.* TO `sail`@`%%`;\'',
            $database,
            $testing,
            $database,
            $testing,
        );

        return [$this->sail($root), 'exec', '-T', 'mysql', 'bash', '-c', $statement];
    }

    /** @return non-empty-list<string> */
    public function dropMySqlDatabases(string $root): array
    {
        $database = DatabaseName::forPath($root);
        $testing = DatabaseName::testingForPath($root);
        $statement = sprintf(
            'for attempt in {1..30}; do MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqladmin --user=root ping --silent && break; sleep 1; done; MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql --user=root --execute=\'DROP DATABASE IF EXISTS `%s`; DROP DATABASE IF EXISTS `%s`;\'',
            $database,
            $testing,
        );

        return [$this->sail($root), 'exec', '-T', 'mysql', 'bash', '-c', $statement];
    }

    /** @return non-empty-list<string> */
    public function herd(string $action, string ...$arguments): array
    {
        $herd = $this->executables->herd();

        if ($herd === null) {
            throw new EnvironmentException('Laravel Herd is configured but its executable cannot be found.');
        }

        return [$herd, $action, ...array_values($arguments)];
    }

    /** @return non-empty-list<string> */
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

    /** @return non-empty-list<string> */
    private function herdPrefix(string $tool, string $root): array
    {
        $herd = $this->required($this->executables->herd(), 'Laravel Herd');

        return match ($tool) {
            'artisan' => [$herd, 'php', $root.'/artisan'],
            'composer' => [$herd, 'composer'],
            'php' => [$herd, 'php'],
            'test' => [$herd, 'php', $root.'/artisan', 'test'],
            'npm' => [$this->required($this->executables->find('npm'), 'npm')],
            default => throw new EnvironmentException("Unknown runtime tool [{$tool}]."),
        };
    }

    /** @return non-empty-list<string> */
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

    private function sail(string $root): string
    {
        $path = $root.'/vendor/bin/sail';

        if (! is_executable($path)) {
            throw new EnvironmentException('Laravel Sail is configured but vendor/bin/sail is missing or not executable.');
        }

        return $path;
    }

    /** @return list<string> */
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

    private function required(?string $path, string $name): string
    {
        if ($path === null) {
            throw new EnvironmentException("{$name} is required but its executable cannot be found.");
        }

        return $path;
    }
}
