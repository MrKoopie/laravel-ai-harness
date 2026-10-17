<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Environment;

use Closure;
use Dotenv\Dotenv;
use MrKoopie\LaravelAiHarness\Files\SafeWriter;
use PDO;

/** Manage path-derived databases on an existing local server, without managing the service. */
final readonly class HostMySql
{
    /** @param (Closure(array<string, string>): PDO)|null $connect */
    public function __construct(private ?Closure $connect = null) {}

    /** Reject connections that could override the checkout's generated database selection. */
    public static function validate(string $root): void
    {
        foreach (array_keys(getenv()) as $key) {
            if (str_starts_with($key, 'DB_') || in_array($key, ['DATABASE_URL', 'MYSQL_ATTR_SSL_CA'], true)) {
                throw new EnvironmentException("Unset process {$key} before using harness-managed host MySQL.");
            }
        }
        $cache = getenv('APP_CONFIG_CACHE');
        if ($cache !== false && $cache !== '' && $cache !== 'bootstrap/cache/config.php') {
            throw new EnvironmentException('Host MySQL requires the default Laravel configuration cache path.');
        }
        foreach (['.env', '.env.testing', 'phpunit.xml', 'phpunit.xml.dist'] as $file) {
            (new SafeWriter)->assertSafePath($root, $file);
            if (is_link($root.'/'.$file)) {
                throw new EnvironmentException('Host MySQL requires regular environment and PHPUnit files.');
            }
        }
        $connection = self::connection($root);
        foreach ($connection as $value) {
            if (str_contains($value, '`') && str_contains($value, "'")) {
                throw new EnvironmentException('MySQL connection values cannot combine backticks and apostrophes.');
            }
        }
    }

    /** @return array<string, string> */
    private static function connection(string $root): array
    {
        $path = is_file($root.'/.env') ? '.env' : '.env.example';
        if (is_link($root.'/'.$path) || ! is_file($root.'/'.$path)) {
            throw new EnvironmentException('Host MySQL requires a regular .env or .env.example file.');
        }
        if (filesize($root.'/'.$path) > 1048576) {
            throw new EnvironmentException('Host MySQL environment file exceeds 1 MiB.');
        }
        try {
            $values = Dotenv::createArrayBacked($root, $path)->load();
        } catch (\Throwable) {
            throw new EnvironmentException('Unable to parse the local MySQL environment file; check dotenv syntax.');
        }
        foreach (['DB_URL', 'DATABASE_URL', 'MYSQL_ATTR_SSL_CA', 'APP_CONFIG_CACHE'] as $alias) {
            if (! in_array($values[$alias] ?? '', ['', 'null', '(null)', $alias === 'APP_CONFIG_CACHE' ? 'bootstrap/cache/config.php' : ''], true)) {
                throw new EnvironmentException("Clear {$alias} before using harness-managed host MySQL.");
            }
        }
        $host = $values['DB_HOST'] ?? '127.0.0.1';
        $port = $values['DB_PORT'] ?? '3306';
        $socket = $values['DB_SOCKET'] ?? '';
        if (! in_array($host, ['127.0.0.1', 'localhost', '::1'], true) || ! ctype_digit($port) || (int) $port < 1 || (int) $port > 65535
            || ($socket !== '' && (! str_starts_with($socket, '/') || preg_match('/[;\x00-\x1f]/', $socket) === 1))) {
            throw new EnvironmentException('Host MySQL requires a loopback host, valid port, and an optional absolute local socket.');
        }

        return [
            'host' => $host, 'port' => $port, 'socket' => $socket,
            'username' => $values['DB_USERNAME'] ?? 'root',
            'password' => in_array($values['DB_PASSWORD'] ?? '', ['null', '(null)'], true) ? '' : ($values['DB_PASSWORD'] ?? ''),
        ];
    }

    /** Run under the selected PHP runtime, with a testable PDO boundary. */
    public function run(string $root, string $action): void
    {
        if (! in_array($action, ['setup', 'cleanup'], true)) {
            throw new EnvironmentException('Unknown host MySQL action.');
        }
        $writer = new SafeWriter;
        $state = new StateStore($writer);
        $owned = $state->hostMySql($root);
        if ($action === 'cleanup' && $owned === null) {
            return;
        }
        self::validate($root);
        $connection = self::connection($root);
        $identity = $connection;
        unset($identity['password']);
        $identity['database'] = DatabaseName::forPath($root);
        $identity['testing'] = DatabaseName::testingForPath($root);
        if ($owned !== null && $owned !== $identity) {
            throw new EnvironmentException('Host MySQL ownership does not match this checkout or connection; restore its original endpoint before retrying.');
        }
        $pdo = $this->connect !== null ? ($this->connect)($connection) : new PDO(
            $connection['socket'] !== '' ? 'mysql:unix_socket='.$connection['socket'].';charset=utf8mb4' : 'mysql:host='.$connection['host'].';port='.$connection['port'].';charset=utf8mb4',
            $connection['username'], $connection['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $query = $pdo->query('SHOW DATABASES');
        if ($query === false) {
            throw new EnvironmentException('Unable to enumerate local MySQL databases.');
        }
        $databases = $query->fetchAll(PDO::FETCH_COLUMN);
        $database = $identity['database'];
        $testing = $identity['testing'];
        $pattern = '/^'.preg_quote($testing, '/').'(_test)?_[0-9]+$/D';
        if ($action === 'setup') {
            if ($owned === null && array_filter($databases, static fn ($name): bool => is_string($name) && ($name === $database || $name === $testing || preg_match($pattern, $name) === 1)) !== []) {
                throw new EnvironmentException('Refusing to adopt existing MySQL databases without a harness ownership record.');
            }
            // Record before DDL so partial creation is retryable, without recording passwords.
            $state->recordHostMySql($root, $identity);
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$database}`");
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$testing}`");
            $environment = new EnvironmentFile($writer);
            $environment->ensure($root);
            $environment->clearCloudConfigCache($root);
            $environment->configureHostMySql($root, $connection);
        } else {
            foreach ($databases as $name) {
                if (is_string($name) && preg_match($pattern, $name) === 1) {
                    $pdo->exec("DROP DATABASE IF EXISTS `{$name}`");
                }
            }
            $pdo->exec("DROP DATABASE IF EXISTS `{$testing}`");
            $pdo->exec("DROP DATABASE IF EXISTS `{$database}`");
            $state->clearHostMySql($root);
        }
    }
}
