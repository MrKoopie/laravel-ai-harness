<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Environment;

use MrKoopie\LaravelAiHarness\Files\FileException;
use MrKoopie\LaravelAiHarness\Files\SafeWriter;

final readonly class EnvironmentFile
{
    private const MAX_FILE_SIZE = 1_048_576;

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
