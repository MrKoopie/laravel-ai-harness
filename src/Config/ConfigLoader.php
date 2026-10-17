<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Config;

use MrKoopie\LaravelAiHarness\Environment\ExecutionEnvironment;
use MrKoopie\LaravelAiHarness\Environment\Runtime;
use MrKoopie\LaravelAiHarness\Support\GlobalInstallation;
use MrKoopie\LaravelAiHarness\Support\PrimaryCheckout;

final class ConfigLoader
{
    private const MAX_FILE_SIZE = 65_536;

    private const MAX_VALUE_LENGTH = 4_096;

    /** @var array<string, string> */
    private const DEFAULTS = [
        'runtime' => 'native',
        'agents' => 'codex,claude',
        'sail_services' => '',
        'site_secure' => 'true',
        'php_version' => '',
        'db_connection' => 'auto',
        'worktrees' => 'true',
        'cloud' => 'true',
        'cloud_services' => 'mysql',
        'cloud_migrate' => 'false',
        'cloud_seed' => 'false',
        'cloud_build' => 'false',
        'cloud_browser' => 'false',
    ];

    /** @var array<string, string> */
    public const ENVIRONMENT = [
        'AI_HARNESS_RUNTIME' => 'runtime',
        'AI_HARNESS_PHP_VERSION' => 'php_version',
        'AI_HARNESS_SITE_SECURE' => 'site_secure',
        'AI_HARNESS_DB_CONNECTION' => 'db_connection',
        'AI_HARNESS_AGENTS' => 'agents',
        'AI_HARNESS_SAIL_SERVICES' => 'sail_services',
        'AI_HARNESS_WORKTREES' => 'worktrees',
        'AI_HARNESS_CLOUD' => 'cloud',
        'AI_HARNESS_CLOUD_SERVICES' => 'cloud_services',
        'AI_HARNESS_CLOUD_MIGRATE' => 'cloud_migrate',
        'AI_HARNESS_CLOUD_SEED' => 'cloud_seed',
        'AI_HARNESS_CLOUD_BUILD' => 'cloud_build',
        'AI_HARNESS_CLOUD_BROWSER' => 'cloud_browser',
    ];

    /** @var list<string> */
    private const FILES = [
        '.ai-harness.config.dist',
        '.ai-harness.config',
        GlobalInstallation::CONFIG_FILE,
        '.ai-harness.config.local',
    ];

    /** Load and validate the layered configuration for a project.
     * @param  array<string, string>  $overrides
     */
    public function load(string $root, array $overrides = []): Config
    {
        $values = self::DEFAULTS;
        $sourceFiles = [];
        $sources = array_fill_keys(array_keys(self::DEFAULTS), 'built-in default');
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
                $sources[$key] = $filename;
            }

            $sourceFiles[] = $filename;
        }

        foreach (self::ENVIRONMENT as $variable => $key) {
            $value = getenv($variable);

            if ($value !== false) {
                $this->validateValue($value, $key, $variable);
                $values[$key] = trim($value);
                $sources[$key] = $variable;
            }
        }

        $localJson = getenv('AI_HARNESS_LOCAL_ENV');
        if ($localJson !== false) {
            if (strlen($localJson) > self::MAX_FILE_SIZE) {
                throw new ConfigException('AI_HARNESS_LOCAL_ENV exceeds 64 KiB.');
            }
            try {
                $localValues = json_decode($localJson, flags: JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new ConfigException('AI_HARNESS_LOCAL_ENV must be a JSON object of string values.');
            }
            if (! $localValues instanceof \stdClass) {
                throw new ConfigException('AI_HARNESS_LOCAL_ENV must be a JSON object of string values.');
            }
            foreach (get_object_vars($localValues) as $name => $value) {
                if (! is_string($name) || preg_match('/^[A-Z_][A-Z0-9_]*$/D', $name) !== 1 || ! is_string($value)) {
                    throw new ConfigException('AI_HARNESS_LOCAL_ENV requires uppercase variable names and string values.');
                }
                $this->validateValue($value, 'local_env.'.$name, 'AI_HARNESS_LOCAL_ENV');
                if (! $cloud) {
                    $localEnvironment[$name] = $value;
                    $localEnvironmentSources[$name] = 'AI_HARNESS_LOCAL_ENV';
                }
            }
        }

        foreach ($overrides as $key => $value) {
            if (preg_match('/^local_env\.([A-Z_][A-Z0-9_]*)$/D', $key, $match) === 1) {
                $this->validateValue($value, $key, 'command option');
                if (! $cloud) {
                    $localEnvironment[$match[1]] = $value;
                    $localEnvironmentSources[$match[1]] = 'command option';
                }

                continue;
            }
            if (! array_key_exists($key, self::DEFAULTS)) {
                throw new ConfigException("Unknown configuration key [{$key}].");
            }
            $this->validateValue($value, $key, 'command option');
            $values[$key] = $value;
            $sources[$key] = 'command option';
        }

        if (! in_array($values['db_connection'], ['auto', 'sqlite', 'mysql'], true)) {
            throw new ConfigException('db_connection must be auto, sqlite, or mysql.');
        }

        $runtime = Runtime::tryFrom($values['runtime']);

        if ($runtime === null) {
            throw new ConfigException('runtime must be one of: native, herd, valet, yerd, sail.');
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

        $phpVersion = trim($values['php_version']);
        if ($phpVersion !== '' && preg_match('/^\d+\.\d+$/D', $phpVersion) !== 1) {
            throw new ConfigException('php_version must be empty or a major.minor version such as 8.4.');
        }

        $cloudServices = $this->list($values['cloud_services'], 'cloud_services');

        foreach ($cloudServices as $service) {
            if (! in_array($service, ['mysql', 'redis'], true)) {
                throw new ConfigException('cloud_services supports only mysql and redis.');
            }
        }

        if ($this->boolean($values['cloud_seed'], 'cloud_seed') && ! $this->boolean($values['cloud_migrate'], 'cloud_migrate')) {
            throw new ConfigException('cloud_seed requires cloud_migrate=true; seeders must be safe to rerun.');
        }

        /** @var list<'claude'|'codex'> $agents */
        $config = new Config(
            runtime: $runtime,
            agents: $agents,
            sailServices: $sailServices,
            worktrees: $this->boolean($values['worktrees'], 'worktrees'),
            sourceFiles: $sourceFiles,
            cloud: $this->boolean($values['cloud'], 'cloud'),
            cloudServices: $cloudServices,
            cloudMigrate: $this->boolean($values['cloud_migrate'], 'cloud_migrate'),
            cloudSeed: $this->boolean($values['cloud_seed'], 'cloud_seed'),
            cloudBuild: $this->boolean($values['cloud_build'], 'cloud_build'),
            cloudBrowser: $this->boolean($values['cloud_browser'], 'cloud_browser'),
            localEnvironment: $localEnvironment,
            localEnvironmentSources: $localEnvironmentSources,
            siteSecure: $this->boolean($values['site_secure'], 'site_secure'),
            phpVersion: $phpVersion === '' ? null : $phpVersion,
            sources: $sources,
            dbConnection: $values['db_connection'],
        );

        if (! $cloud && $config->usesHostMySql() && $runtime === Runtime::Sail) {
            throw new ConfigException('A Sail MySQL subset must include mysql in sail_services; use an empty list for the full stack.');
        }
        if (! $cloud && $config->dbConnection === 'sqlite' && $config->managesMySql()) {
            throw new ConfigException('db_connection=sqlite conflicts with harness-managed Sail MySQL.');
        }

        $this->validateLocalEnvironment($config);

        return $config;
    }

    /** @return array<non-empty-string, non-empty-string> Ordered display names and file paths. */
    private function files(string $root, bool $cloud): array
    {
        $files = [];

        foreach (self::FILES as $filename) {
            if (in_array($filename, [GlobalInstallation::CONFIG_FILE, '.ai-harness.config.local'], true) && ! $cloud) {
                $primary = PrimaryCheckout::forWorktree($root);

                if ($primary !== null && realpath($primary) !== realpath($root)) {
                    $path = $primary.'/'.$filename;
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
                || ($name === 'APP_URL' && in_array($config->runtime, [Runtime::Herd, Runtime::Valet, Runtime::Yerd], true))
                || ($config->managesMySql() && (str_starts_with($name, 'DB_') || in_array($name, ['DATABASE_URL', 'MYSQL_ATTR_SSL_CA'], true)));

            $managed = $managed || (($config->usesHostMySql() || $config->dbConnection === 'sqlite')
                && in_array($name, ['DB_CONNECTION', 'DB_DATABASE'], true));

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

            $this->validateValue($value, $key, $path.':'.($index + 1));

            $values[$key] = $value;
        }

        return $values;
    }

    /** Apply the same literal value limits to files and process overrides. */
    private function validateValue(string $value, string $key, string $source): void
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new ConfigException("Configuration value [{$key}] at {$source} contains a control character.");
        }

        if (strlen($value) > self::MAX_VALUE_LENGTH) {
            throw new ConfigException("Configuration value [{$key}] at {$source} exceeds 4096 bytes.");
        }
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
