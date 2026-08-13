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

    public function __construct(private SafeWriter $writer) {}

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

    public function setAppUrl(string $root, string $url): void
    {
        $this->replaceValues($root, '.env', ['APP_URL' => $url]);
    }

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

    /**
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
     * @param  array<string, string>  $values
     */
    private function withValues(string $contents, array $values): string
    {
        foreach ($values as $key => $value) {
            $pattern = '/^'.preg_quote($key, '/').'=.*/m';
            $replacement = $key.'='.$value;
            $updated = preg_replace($pattern, $replacement, $contents, 1, $count);

            if ($updated === null) {
                throw new FileException("Unable to update environment value [{$key}].");
            }

            $contents = $count === 1 ? $updated : rtrim($contents)."\n".$replacement."\n";
        }

        return $contents;
    }

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
