<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Files;

use JsonException;
use stdClass;

final readonly class ComposerScripts
{
    public const MARKER = 'laravel-ai-harness:update';

    private const EVENTS = ['post-install-cmd', 'post-update-cmd'];

    private const MAX_FILE_SIZE = 1_048_576;

    /** Create a Composer script manager backed by safe writes. */
    public function __construct(private SafeWriter $writer) {}

    /** Add the guarded project refresh hook while preserving unrelated scripts. */
    public function sync(string $root): bool
    {
        $path = $root.'/composer.json';

        if (! is_file($path)) {
            return false;
        }

        $this->writer->assertSafePath($root, 'composer.json');

        if (is_link($path)) {
            throw new FileException("Refusing to edit symbolic link [{$path}].");
        }

        $size = filesize($path);

        if ($size === false || $size > self::MAX_FILE_SIZE) {
            throw new FileException("composer.json [{$path}] exceeds 1 MiB or cannot be read.");
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new FileException("Unable to read composer.json [{$path}].");
        }

        try {
            $composer = json_decode($contents, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new FileException('Invalid composer.json: '.$exception->getMessage(), previous: $exception);
        }

        if (! $composer instanceof stdClass) {
            throw new FileException("composer.json [{$path}] must contain a JSON object.");
        }

        if (! isset($composer->scripts)) {
            $composer->scripts = new stdClass;
        }

        if (! $composer->scripts instanceof stdClass) {
            throw new FileException("Composer scripts in [{$path}] must be a JSON object.");
        }

        foreach (self::EVENTS as $event) {
            $current = property_exists($composer->scripts, $event) ? $composer->scripts->{$event} : [];
            $scripts = $this->scriptList($current, $event, $path);
            $scripts = array_values(array_filter($scripts, fn (string $script): bool => ! $this->isHarnessHook($script)));
            $scripts[] = self::hook();
            $composer->scripts->{$event} = $scripts;
        }

        try {
            $encoded = json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        } catch (JsonException $exception) {
            throw new FileException('Unable to encode composer.json: '.$exception->getMessage(), previous: $exception);
        }

        if ($contents === $encoded) {
            return false;
        }

        $this->writer->write($root, 'composer.json', $encoded);

        return true;
    }

    /** Determine whether both managed refresh hooks are present exactly once. */
    public function installed(string $root): bool
    {
        $path = $root.'/composer.json';
        $contents = is_file($path) && ! is_link($path) ? file_get_contents($path) : false;

        if (! is_string($contents)) {
            return false;
        }

        try {
            $composer = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return false;
        }

        if (! is_array($composer) || ! is_array($composer['scripts'] ?? null)) {
            return false;
        }

        foreach (self::EVENTS as $event) {
            $value = $composer['scripts'][$event] ?? null;
            $scripts = is_string($value) ? [$value] : $value;

            if (! is_array($scripts)) {
                return false;
            }

            $managed = array_filter(
                $scripts,
                static fn (mixed $script): bool => is_string($script) && $script === self::hook(),
            );

            if (count($managed) !== 1) {
                return false;
            }
        }

        return true;
    }

    /** Return the Composer command used for automatic project refreshes. */
    public static function hook(): string
    {
        return '@php -r "/* '.self::MARKER.' */ if (getenv(\'COMPOSER_DEV_MODE\') === \'0\' || ! is_file(\'vendor/bin/ai-harness\')) { exit(0); } passthru(escapeshellarg(PHP_BINARY).\' \'.escapeshellarg(\'vendor/bin/ai-harness\').\' update --no-interaction\', \\$status); exit(\\$status);"';
    }

    /**
     * Normalize one Composer event while rejecting lossy input.
     *
     * @return list<string>
     */
    private function scriptList(mixed $value, string $event, string $path): array
    {
        if (is_string($value)) {
            return [$value];
        }

        if (! is_array($value) || ! array_is_list($value)) {
            throw new FileException("Composer script [{$event}] in [{$path}] must be a string or an array of strings.");
        }

        foreach ($value as $script) {
            if (! is_string($script)) {
                throw new FileException("Composer script [{$event}] in [{$path}] must be a string or an array of strings.");
            }
        }

        return $value;
    }

    /** Identify current hooks and the exact shape installed by v0.1. */
    private function isHarnessHook(string $script): bool
    {
        if ($script === self::hook()) {
            return true;
        }

        $prefix = '@php -r "if (file_exists(\'vendor/mrkoopie/laravel-ai-harness\')) { passthru(escapeshellarg(PHP_BINARY).\' artisan ai-harness:update --ansi';
        $suffix = '\', $code); exit($code); }"';

        if (! str_starts_with($script, $prefix) || ! str_ends_with($script, $suffix)) {
            return false;
        }

        $flags = substr($script, strlen($prefix), -strlen($suffix));

        return $flags === '' || preg_match('/^(?: --with=[a-z0-9_-]+)+$/', $flags) === 1;
    }
}
