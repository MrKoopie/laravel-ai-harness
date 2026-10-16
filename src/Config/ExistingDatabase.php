<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Config;

use Dotenv\Dotenv;
use MrKoopie\LaravelAiHarness\Environment\DatabaseName;
use MrKoopie\LaravelAiHarness\Environment\StateStore;
use MrKoopie\LaravelAiHarness\Files\SafeWriter;
use Throwable;

/** Connection data is deliberately never included in diagnostics or state. */
final readonly class ExistingDatabase
{
    /** @var array<string, string> */
    public const ENVIRONMENT = [
        'AI_HARNESS_DB_CONNECTION' => 'DB_CONNECTION',
        'AI_HARNESS_DB_HOST' => 'DB_HOST',
        'AI_HARNESS_DB_PORT' => 'DB_PORT',
        'AI_HARNESS_DB_SOCKET' => 'DB_SOCKET',
        'AI_HARNESS_DB_USERNAME' => 'DB_USERNAME',
        'AI_HARNESS_DB_PASSWORD' => 'DB_PASSWORD',
        'AI_HARNESS_DB_DATABASE' => 'DB_DATABASE',
    ];

    /** Keep application and administrative credentials separate without persisting either in ownership state.
     * @param  list<string>  $environmentOverrides
     */
    public function __construct(
        public string $host,
        public int $port,
        public string $socket,
        public string $username,
        public string $password,
        public string $userHost,
        public string $provisionUsername,
        public string $provisionPassword,
        public int $parallelProcesses,
        public string $connection = 'mysql',
        public string $sslCa = '',
        public ?string $database = null,
        public array $environmentOverrides = [],
    ) {}

    /** Resolve the same application settings that setup will copy and override.
     * @param  array<string, string>  $values
     * @param  array<string, string>  $overrides
     */
    public static function forProject(string $root, array $values, array $overrides): ?self
    {
        $file = is_file($root.'/.env') || is_link($root.'/.env') ? '.env' : '.env.example';
        $environment = self::environment($root.'/'.$file);
        $environment = [...$environment, ...$overrides];
        $processOverrides = [];
        foreach (self::ENVIRONMENT as $variable => $key) {
            $value = getenv($variable);
            if ($value !== false) {
                if (strlen($value) > 4096 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                    throw new ConfigException("{$variable} exceeds 4096 bytes or contains a control character.");
                }
                $environment[$key] = $value;
                $processOverrides[] = $variable;
            }
        }
        $driver = $environment['DB_CONNECTION'] ?? '';
        if (getenv('AI_HARNESS_DB_CONNECTION') !== false && ! in_array($driver, ['mysql', 'mariadb'], true)) {
            throw new ConfigException('AI_HARNESS_DB_CONNECTION must be mysql or mariadb.');
        }
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return null;
        }
        $host = $environment['DB_HOST'] ?? '127.0.0.1';
        $port = $environment['DB_PORT'] ?? '3306';
        $socket = $environment['DB_SOCKET'] ?? '';
        $username = $environment['DB_USERNAME'] ?? 'root';
        $password = $environment['DB_PASSWORD'] ?? '';
        if (getenv('AI_HARNESS_DB_USERNAME') === false) {
            $username = self::dotenvCredential($username);
        }
        if (getenv('AI_HARNESS_DB_PASSWORD') === false) {
            $password = self::dotenvCredential($password);
        }
        foreach (['DB_PORT' => [$port, 65535], 'database_parallel_processes' => [$values['database_parallel_processes'], 64]] as $key => [$value, $max]) {
            if (! ctype_digit($value) || (int) $value > $max || ($key === 'DB_PORT' && (int) $value < 1)) {
                throw new ConfigException("{$key} is outside its supported range.");
            }
        }
        foreach (['DB_HOST' => $host, 'DB_SOCKET' => $socket] as $key => $value) {
            if (str_contains($value, ';') || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                throw new ConfigException("{$key} must not contain control characters or a DSN separator.");
            }
        }
        if ($username === '' || ($host === '' && $socket === '')) {
            throw new ConfigException('Existing MySQL/MariaDB provisioning requires DB_USERNAME and DB_HOST or DB_SOCKET in .env.');
        }
        $separate = $values['database_provision_username'] !== '';
        if (! $separate && $values['database_provision_password'] !== '') {
            throw new ConfigException('database_provision_password requires database_provision_username.');
        }

        $database = getenv('AI_HARNESS_DB_DATABASE');
        if ($database === false) {
            // Retain the selected pair after a one-command override; ownership is verified on the server.
            $record = (new StateStore(new SafeWriter))->existingDatabases($root);
            $database = ($record['owner'] ?? null) === hash('sha256', $root) ? ($record['database'] ?? null) : null;
        }
        if ($database === '') {
            $database = null;
        }
        if ($database !== null && (! is_string($database) || preg_match('/^[A-Za-z0-9_]{1,48}$/D', $database) !== 1 || $database === 'ai_harness_registry')) {
            throw new ConfigException('AI_HARNESS_DB_DATABASE must contain 1–48 letters, digits or underscores and must not name the harness registry.');
        }

        return new self($host, (int) $port, $socket, $username, $password, '', $separate ? $values['database_provision_username'] : $username, $separate ? $values['database_provision_password'] : $password, (int) $values['database_parallel_processes'], $driver, $environment['MYSQL_ATTR_SSL_CA'] ?? '', $database, $processOverrides);
    }

    /** Match Laravel's reserved dotenv scalars while preserving explicitly quoted credentials. */
    public static function dotenvCredential(string $value): string
    {
        return match (strtolower($value)) {
            'true', '(true)' => '1',
            'false', '(false)', 'null', '(null)', 'empty', '(empty)' => '',
            default => preg_match('/\A([\'"])(.*)\1\z/s', $value, $matches) === 1 ? $matches[2] : $value,
        };
    }

    /** Parse dotenv as data, without changing the process environment or loading application PHP.
     * @return array<string, string>
     */
    private static function environment(string $path): array
    {
        if (is_link($path)) {
            throw new ConfigException('Refusing to read database settings from a symbolic link.');
        }
        if (! is_file($path)) {
            return [];
        }
        $size = filesize($path);
        if ($size === false || $size > 1_048_576) {
            throw new ConfigException('The application environment file exceeds 1 MiB or cannot be read.');
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new ConfigException('Unable to read the application environment file.');
        }
        try {
            $parsed = Dotenv::parse($contents);
        } catch (Throwable) {
            // Parser diagnostics include the original line, which may contain a password.
            throw new ConfigException('Unable to parse database settings from the application environment file; check dotenv syntax (values redacted).');
        }
        $result = [];
        foreach ($parsed as $name => $value) {
            $result[$name] = $value ?? '';
        }

        return $result;
    }

    /** Return the generated or explicitly selected development database name. */
    public function developmentName(string $root): string
    {
        return $this->database ?? DatabaseName::forPath($root);
    }

    /** Keep testing isolated from the selected development database. */
    public function testingName(string $root): string
    {
        return $this->database !== null ? $this->database.'_testing' : DatabaseName::testingForPath($root);
    }

    /** Identify the selected transport; TCP settings do not affect a socket connection. */
    public function endpoint(): string
    {
        return $this->socket !== '' ? hash('sha256', 'socket:'.$this->socket) : $this->legacyEndpoint();
    }

    /** Recognize a pre-canonicalization ownership record using its exact original settings. */
    public function legacyEndpoint(): string
    {
        return hash('sha256', $this->host.':'.$this->port.':'.$this->socket);
    }
}
