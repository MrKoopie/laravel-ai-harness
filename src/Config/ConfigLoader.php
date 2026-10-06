<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Config;

use MrKoopie\LaravelAiHarness\Environment\ExecutionEnvironment;
use MrKoopie\LaravelAiHarness\Environment\Runtime;
use MrKoopie\LaravelAiHarness\Environment\Services;
use MrKoopie\LaravelAiHarness\Support\PrimaryCheckout;

final class ConfigLoader
{
    private const MAX_FILE_SIZE = 65_536;

    private const MAX_VALUE_LENGTH = 4_096;

    /** @var array<string, string> */
    private const DEFAULTS = [
        'runtime' => 'native',
        'services' => 'none',
        'agents' => 'codex,claude',
        'sail_services' => '',
        'herd_secure' => 'true',
        'herd_php' => '',
        'valet_secure' => 'true',
        'valet_php' => '',
        'worktrees' => 'true',
        'cloud' => 'true',
        'cloud_services' => 'mysql',
        'cloud_migrate' => 'false',
        'cloud_seed' => 'false',
        'cloud_build' => 'false',
        'cloud_browser' => 'false',
    ];

    /** @var list<string> */
    private const FILES = [
        '.ai-harness.config.dist',
        '.ai-harness.config',
        '.ai-harness.config.local',
    ];

    /** Load and validate the layered configuration for a project. */
    public function load(string $root): Config
    {
        $values = self::DEFAULTS;
        $sourceFiles = [];
        $localEnvironment = [];
        $localEnvironmentSources = [];
        $cloud = ExecutionEnvironment::current()->isCloud();

        foreach ($this->files($root, $cloud) as $filename => $path) {
            if (! is_file($path)) {
                continue;
            }

            foreach ($this->parseFile($path) as $key => $value) {
                if (str_starts_with($key, 'local_env.')) {
                    if (! $cloud) {
                        $name = substr($key, 10);
                        $localEnvironment[$name] = $value;
                        $localEnvironmentSources[$name] = $filename;
                    }

                    continue;
                }

                $values[$key] = $value;
            }

            $sourceFiles[] = $filename;
        }

        $runtime = Runtime::tryFrom($values['runtime']);

        if ($runtime === null) {
            throw new ConfigException('runtime must be one of: native, herd, valet, sail.');
        }

        $services = Services::tryFrom($values['services']);

        if ($services === null) {
            throw new ConfigException('services must be one of: none, sail.');
        }

        $agents = $this->list($values['agents'], 'agents');

        foreach ($agents as $agent) {
            if (! in_array($agent, ['claude', 'codex'], true)) {
                throw new ConfigException("Unsupported agent [{$agent}]. Supported agents: codex, claude.");
            }
        }

        $sailServices = $this->list($values['sail_services'], 'sail_services');

        foreach ($sailServices as $service) {
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/', $service) !== 1) {
                throw new ConfigException("Invalid Sail service name [{$service}].");
            }
        }

        if ($services !== Services::Sail && $sailServices !== []) {
            throw new ConfigException('sail_services may only be set when services=sail.');
        }

        $herdPhp = trim($values['herd_php']);
        $valetPhp = trim($values['valet_php']);

        $cloudServices = $this->list($values['cloud_services'], 'cloud_services');

        foreach ($cloudServices as $service) {
            if (! in_array($service, ['mysql', 'redis'], true)) {
                throw new ConfigException('cloud_services supports only mysql and redis.');
            }
        }

        if ($this->boolean($values['cloud_seed'], 'cloud_seed') && ! $this->boolean($values['cloud_migrate'], 'cloud_migrate')) {
            throw new ConfigException('cloud_seed requires cloud_migrate=true; seeders must be safe to rerun.');
        }

        if ($herdPhp !== '' && preg_match('/^\d+\.\d+$/', $herdPhp) !== 1) {
            throw new ConfigException('herd_php must be empty or a major.minor version such as 8.4.');
        }

        if ($valetPhp !== '' && preg_match('/^\d+\.\d+$/', $valetPhp) !== 1) {
            throw new ConfigException('valet_php must be empty or a major.minor version such as 8.4.');
        }

        /** @var list<'claude'|'codex'> $agents */
        $config = new Config(
            runtime: $runtime,
            services: $services,
            agents: $agents,
            sailServices: $sailServices,
            herdSecure: $this->boolean($values['herd_secure'], 'herd_secure'),
            herdPhp: $herdPhp === '' ? null : $herdPhp,
            worktrees: $this->boolean($values['worktrees'], 'worktrees'),
            sourceFiles: $sourceFiles,
            cloud: $this->boolean($values['cloud'], 'cloud'),
            cloudServices: $cloudServices,
            cloudMigrate: $this->boolean($values['cloud_migrate'], 'cloud_migrate'),
            cloudSeed: $this->boolean($values['cloud_seed'], 'cloud_seed'),
            cloudBuild: $this->boolean($values['cloud_build'], 'cloud_build'),
            cloudBrowser: $this->boolean($values['cloud_browser'], 'cloud_browser'),
            valetSecure: $this->boolean($values['valet_secure'], 'valet_secure'),
            valetPhp: $valetPhp === '' ? null : $valetPhp,
            localEnvironment: $localEnvironment,
            localEnvironmentSources: $localEnvironmentSources,
        );

        $this->validateLocalEnvironment($config);

        return $config;
    }

    /** @return array<non-empty-string, non-empty-string> Ordered display names and file paths. */
    private function files(string $root, bool $cloud): array
    {
        $files = [];

        foreach (self::FILES as $filename) {
            if ($filename === '.ai-harness.config.local' && ! $cloud) {
                $primary = PrimaryCheckout::forWorktree($root);

                if ($primary !== null && realpath($primary) !== realpath($root)) {
                    $path = $primary.'/.ai-harness.config.local';
                    $files[$path] = $path;
                }
            }

            $files[$filename] = $root.DIRECTORY_SEPARATOR.$filename;
        }

        return $files;
    }

    /** Validate effective local values without disclosing credentials in errors. */
    private function validateLocalEnvironment(Config $config): void
    {
        foreach ($config->localEnvironment as $name => $value) {
            if (str_contains($value, '`') && str_contains($value, "'")) {
                throw new ConfigException("local_env.{$name} cannot combine backticks with apostrophes; that literal cannot be represented safely for both Laravel and Sail.");
            }

            $managed = in_array($name, ['APP_KEY', 'APP_ENV', 'APP_CONFIG_CACHE'], true)
                || ($name === 'APP_URL' && in_array($config->runtime, [Runtime::Herd, Runtime::Valet], true))
                || ($config->managesMySql() && (str_starts_with($name, 'DB_') || in_array($name, ['DATABASE_URL', 'MYSQL_ATTR_SSL_CA'], true)));

            if ($managed) {
                throw new ConfigException("local_env.{$name} is managed by the harness for this runtime; use local_env.FORWARD_DB_PORT for a Sail host port.");
            }

            if (in_array($name, ['DB_PORT', 'FORWARD_DB_PORT', 'REDIS_PORT', 'FORWARD_REDIS_PORT', 'MAIL_PORT', 'FORWARD_MAILPIT_PORT', 'FORWARD_MAILPIT_DASHBOARD_PORT', 'APP_PORT', 'VITE_PORT'], true)
                && (! ctype_digit($value) || (int) $value < 1 || (int) $value > 65535)) {
                throw new ConfigException("local_env.{$name} must be a TCP port between 1 and 65535.");
            }
        }
    }

    /**
     * Parse one harness configuration file.
     *
     * @return array<string, string>
     */
    private function parseFile(string $path): array
    {
        $size = filesize($path);

        if ($size === false || $size > self::MAX_FILE_SIZE) {
            throw new ConfigException("Configuration file [{$path}] exceeds 64 KiB or cannot be read.");
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            throw new ConfigException("Unable to read configuration file [{$path}].");
        }

        $values = [];

        foreach ($lines as $index => $line) {
            $trimmed = trim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '#') || str_starts_with($trimmed, ';')) {
                continue;
            }

            if (! str_contains($trimmed, '=')) {
                throw new ConfigException(sprintf('Invalid configuration at %s:%d; expected key=value.', $path, $index + 1));
            }

            [$key, $rawValue] = array_map('trim', explode('=', $trimmed, 2));

            if (! array_key_exists($key, self::DEFAULTS) && preg_match('/^local_env\.[A-Z_][A-Z0-9_]*$/', $key) !== 1) {
                throw new ConfigException(sprintf('Unknown configuration key [%s] at %s:%d.', $key, $path, $index + 1));
            }

            if (array_key_exists($key, $values)) {
                throw new ConfigException(sprintf('Duplicate configuration key [%s] at %s:%d.', $key, $path, $index + 1));
            }

            $value = $this->unquote($rawValue, $path, $index + 1);

            if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                throw new ConfigException(sprintf('Configuration value [%s] at %s:%d contains a control character.', $key, $path, $index + 1));
            }

            if (strlen($value) > self::MAX_VALUE_LENGTH) {
                throw new ConfigException(sprintf('Configuration value [%s] at %s:%d exceeds 4096 bytes.', $key, $path, $index + 1));
            }

            $values[$key] = $value;
        }

        return $values;
    }

    /** Remove balanced quotes from a configuration value. */
    private function unquote(string $value, string $path, int $line): string
    {
        if ($value === '') {
            return '';
        }

        $quote = $value[0];

        if ($quote !== '"' && $quote !== "'") {
            return $value;
        }

        if (strlen($value) < 2 || $value[strlen($value) - 1] !== $quote) {
            throw new ConfigException("Unterminated quoted value at {$path}:{$line}.");
        }

        return substr($value, 1, -1);
    }

    /**
     * Parse a comma-separated configuration value into unique items.
     *
     * @return list<non-empty-string>
     */
    private function list(string $value, string $key): array
    {
        if (trim($value) === '') {
            return [];
        }

        $items = array_map('trim', explode(',', $value));

        if (in_array('', $items, true)) {
            throw new ConfigException("{$key} contains an empty list item.");
        }

        return array_values(array_unique($items));
    }

    /** Parse a supported textual boolean value. */
    private function boolean(string $value, string $key): bool
    {
        return match (strtolower(trim($value))) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => throw new ConfigException("{$key} must be true or false."),
        };
    }
}
