<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Environment;

use MrKoopie\LaravelAiHarness\Files\FileException;
use MrKoopie\LaravelAiHarness\Files\SafeWriter;

final readonly class EnvironmentFile
{
    private const MAX_FILE_SIZE = 1_048_576;

    /** @var array<string, string> */
    private const TESTING_VALUES = [
        'APP_ENV' => 'testing',
        'APP_URL' => 'http://localhost',
        'BROADCAST_CONNECTION' => 'null',
        'CACHE_STORE' => 'array',
        'DB_CONNECTION' => 'sqlite',
        'DB_DATABASE' => ':memory:',
        'MAIL_MAILER' => 'array',
        'QUEUE_CONNECTION' => 'sync',
        'SESSION_DRIVER' => 'array',
    ];

    /** Create an environment-file manager backed by safe writes. */
    public function __construct(private SafeWriter $writer) {}

    /** Create .env from .env.example when it is missing. */
    public function ensure(string $root): bool
    {
        $target = $root.'/.env';

        if (is_file($target)) {
            return false;
        }

        if (is_link($target)) {
            throw new FileException("Refusing to replace symbolic link [{$target}].");
        }

        $example = $root.'/.env.example';

        if (! is_file($example)) {
            return false;
        }

        $contents = $this->read($example);
        $this->writer->write($root, '.env', $contents);

        return true;
    }

    /** Determine whether the project's application key is missing. */
    public function appKeyMissing(string $root): bool
    {
        $path = $root.'/.env';

        if (! is_file($path) || is_link($path)) {
            return false;
        }

        $contents = $this->read($path);

        if (preg_match('/^APP_KEY=(.*)$/m', $contents, $matches) !== 1) {
            return true;
        }

        return trim(trim($matches[1]), "\"'") === '';
    }

    /** Set the application URL in the primary environment file. */
    public function setAppUrl(string $root, string $url): void
    {
        $this->replaceValues($root, '.env', ['APP_URL' => $url]);
    }

    /** Create an isolated testing environment file when it is missing. */
    public function ensureTesting(string $root): bool
    {
        $target = $root.'/.env.testing';

        if (is_file($target)) {
            return false;
        }

        if (is_link($target)) {
            throw new FileException("Refusing to replace symbolic link [{$target}].");
        }

        $source = $root.'/.env';

        if (! is_file($source)) {
            return false;
        }

        $this->writer->write($root, '.env.testing', $this->withValues($this->read($source), self::TESTING_VALUES));

        return true;
    }

    /** Configure project environment files for checkout-specific MySQL. */
    public function configureMySql(string $root, bool $insideSail): void
    {
        $host = $insideSail ? 'mysql' : '127.0.0.1';
        $port = $insideSail ? '3306' : $this->forwardedMySqlPort($root);
        $database = DatabaseName::forPath($root);
        $values = [
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => $host,
            'DB_PORT' => $port,
            'DB_DATABASE' => $database,
            'DB_USERNAME' => 'sail',
            'DB_PASSWORD' => 'password',
        ];

        $this->replaceValues($root, '.env', $values);

        $testing = $root.'/.env.testing';

        if (is_file($testing)) {
            $this->replaceValues($root, '.env.testing', [...self::TESTING_VALUES, ...$values, 'DB_DATABASE' => DatabaseName::testingForPath($root)]);
        }
    }

    /** Ensure the testing environment exists and uses MySQL. */
    public function ensureMySqlTesting(string $root, bool $insideSail): bool
    {
        $created = $this->ensureTesting($root);

        $this->configureMySql($root, $insideSail);

        return $created;
    }

    /** Configure only local cloud services, never inherited external database hosts. */
    public function configureCloud(string $root, bool $mysql, bool $redis): void
    {
        $socket = getenv('AI_HARNESS_MYSQL_SOCKET') ?: '/var/run/mysqld/mysqld.sock';

        if (! str_starts_with($socket, '/') || preg_match('/[\s"\x00]/', $socket) === 1) {
            throw new FileException('AI_HARNESS_MYSQL_SOCKET must be an absolute socket path without whitespace or quotes.');
        }

        $values = [
            'APP_ENV' => 'local',
            'APP_CONFIG_CACHE' => 'bootstrap/cache/config.php',
            'APP_URL' => 'http://127.0.0.1:8000',
            'DB_CONNECTION' => $mysql ? 'mysql' : 'sqlite',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '3306',
            'DB_DATABASE' => $mysql ? DatabaseName::forPath($root) : '"'.str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $root.'/database/database.sqlite').'"',
            'DB_USERNAME' => 'harness_'.substr(hash('sha256', $root), 0, 10),
            'DB_PASSWORD' => 'harness',
            'DB_URL' => '',
            'DATABASE_URL' => '',
            'DB_SOCKET' => $mysql ? $socket : '',
            'REDIS_HOST' => '127.0.0.1',
            'REDIS_PORT' => '6379',
            'REDIS_PASSWORD' => 'null',
            'REDIS_URL' => '',
            'CACHE_STORE' => $redis ? 'redis' : 'file',
            'CACHE_DRIVER' => $redis ? 'redis' : 'file',
            'REDIS_CLIENT' => 'phpredis',
            'QUEUE_CONNECTION' => 'sync',
            'SESSION_DRIVER' => 'file',
            'MAIL_MAILER' => 'log',
        ];
        $this->replaceValues($root, '.env', $values);
        $this->ensureTesting($root);
        $this->replaceValues($root, '.env.testing', [
            ...$values,
            ...self::TESTING_VALUES,
            'DB_CONNECTION' => $mysql ? 'mysql' : 'sqlite',
            'DB_DATABASE' => $mysql ? DatabaseName::testingForPath($root) : ':memory:',
        ]);
    }

    /** Copy the generated development key into the isolated testing environment. */
    public function syncTestingKey(string $root): void
    {
        if (preg_match('/^APP_KEY=(.*)$/m', $this->read($root.'/.env'), $matches) === 1) {
            $this->replaceValues($root, '.env.testing', ['APP_KEY' => $matches[1]]);
        }
    }

    /** Discard cached connections before any Laravel Composer script can use them. */
    public function clearCloudConfigCache(string $root): void
    {
        $relative = 'bootstrap/cache/config.php';
        $this->writer->assertSafePath($root, $relative);
        $path = $root.'/'.$relative;

        if (is_link($path)) {
            throw new FileException('Refusing to remove a symbolic link for cached Laravel configuration.');
        }

        if (is_file($path) && ! unlink($path)) {
            throw new FileException('Unable to clear cached Laravel configuration.');
        }
    }

    /** Reconcile inline PHPUnit overrides with the isolated cloud testing environment. */
    public function configureCloudPhpUnit(string $root): void
    {
        $source = is_file($root.'/phpunit.xml') ? 'phpunit.xml' : 'phpunit.xml.dist';

        if (! is_file($root.'/'.$source)) {
            return;
        }

        $contents = $this->read($root.'/'.$source);

        if (stripos($contents, '<!DOCTYPE') !== false) {
            throw new FileException('Cloud PHPUnit configuration must not contain a document type declaration.');
        }

        $document = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);

        try {
            if (! $document->loadXML($contents, LIBXML_NONET)) {
                throw new FileException('Invalid PHPUnit XML configuration.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        preg_match_all('/^([A-Z_]+)=(.*)$/m', $this->read($root.'/.env.testing'), $matches, PREG_SET_ORDER);
        $values = [];

        foreach ($matches as $match) {
            $name = $match[1];

            if (str_starts_with($name, 'DB_') || str_starts_with($name, 'REDIS_') || array_key_exists($name, self::TESTING_VALUES)
                || in_array($name, ['DATABASE_URL', 'APP_CONFIG_CACHE', 'APP_KEY'], true)) {
                $values[$name] = trim($match[2], "\"'");
            }
        }

        $nodes = (new \DOMXPath($document))->query('/phpunit/php/env | /phpunit/php/server');

        if ($nodes !== false) {
            foreach ($nodes as $node) {
                if ($node instanceof \DOMElement && array_key_exists($node->getAttribute('name'), $values)) {
                    $node->setAttribute('value', $values[$node->getAttribute('name')]);
                }
            }
        }

        $updated = $document->saveXML();

        if ($updated === false) {
            throw new FileException('Unable to serialize cloud PHPUnit configuration.');
        }

        $this->writer->write($root, 'phpunit.xml', $updated);
    }

    /** Configure PHPUnit's inline database environment for MySQL. */
    public function configurePhpUnitMySql(string $root): bool
    {
        $path = $root.'/phpunit.xml';

        if (! is_file($path)) {
            return false;
        }

        if (is_link($path)) {
            throw new FileException("Refusing to edit symbolic link [{$path}].");
        }

        $contents = $this->read($path);
        $updated = str_replace('<env name="DB_CONNECTION" value="sqlite"/>', '<env name="DB_CONNECTION" value="mysql"/>', $contents);
        $updated = preg_replace(
            '/(<env\s+name="DB_DATABASE"\s+value=")[^"]*("\s*\/>)/',
            '${1}'.DatabaseName::testingForPath($root).'${2}',
            $updated,
        );

        if ($updated === null) {
            throw new FileException('Unable to configure PHPUnit database settings.');
        }

        if ($updated === $contents) {
            return false;
        }

        $this->writer->write($root, 'phpunit.xml', $updated);

        return true;
    }

    /**
     * Replace environment values in an existing file.
     *
     * @param  array<string, string>  $values
     */
    private function replaceValues(string $root, string $filename, array $values): void
    {
        $path = $root.'/'.$filename;

        if (! is_file($path)) {
            return;
        }

        if (is_link($path)) {
            throw new FileException("Refusing to edit symbolic link [{$path}].");
        }

        $this->writer->write($root, $filename, $this->withValues($this->read($path), $values));
    }

    /**
     * Return environment contents with the supplied values replaced.
     *
     * @param  array<string, string>  $values
     */
    private function withValues(string $contents, array $values): string
    {
        $lines = preg_split('/\R/', $contents);

        if ($lines === false) {
            throw new FileException('Unable to split environment file into lines.');
        }

        foreach ($values as $key => $value) {
            $replacement = $key.'='.$value;
            $found = false;
            $pattern = '/^(?:#\s*)?'.preg_quote($key, '/').'=/';

            foreach ($lines as $index => $line) {
                if (preg_match($pattern, $line) !== 1) {
                    continue;
                }

                if (! $found) {
                    $lines[$index] = $replacement;
                    $found = true;

                    continue;
                }

                unset($lines[$index]);
            }

            if (! $found) {
                $lines[] = $replacement;
            }
        }

        return implode("\n", array_values($lines));
    }

    /** Read and validate the host-side forwarded MySQL port. */
    private function forwardedMySqlPort(string $root): string
    {
        $path = $root.'/.env';

        if (! is_file($path)) {
            return '3306';
        }

        $contents = $this->read($path);

        if (preg_match('/^FORWARD_DB_PORT=(.*)$/m', $contents, $matches) !== 1) {
            return '3306';
        }

        $port = trim(trim($matches[1]), "\"'");

        if ($port === '') {
            return '3306';
        }

        if (! ctype_digit($port) || (int) $port < 1 || (int) $port > 65_535) {
            throw new FileException('FORWARD_DB_PORT must be a TCP port between 1 and 65535.');
        }

        return $port;
    }

    /** Read a bounded environment file. */
    private function read(string $path): string
    {
        $size = filesize($path);

        if ($size === false || $size > self::MAX_FILE_SIZE) {
            throw new FileException("Environment file [{$path}] exceeds 1 MiB or cannot be read.");
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new FileException("Unable to read environment file [{$path}].");
        }

        return $contents;
    }
}
