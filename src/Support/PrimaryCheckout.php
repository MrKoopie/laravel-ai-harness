<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Support;

final class PrimaryCheckout
{
    /** Find a linked checkout's primary directory using verified Git metadata, without running Git. */
    public static function forWorktree(string $root): ?string
    {
        $pointer = self::read($root.'/.git');

        if ($pointer === null || ! str_starts_with($pointer, 'gitdir: ')) {
            return null;
        }

        $gitDirectory = self::resolve($root, substr($pointer, 8));

        if ($gitDirectory === null) {
            return null;
        }

        $commonPointer = self::read($gitDirectory.'/commondir');
        $backPointer = self::read($gitDirectory.'/gitdir');

        if ($commonPointer === null || $backPointer === null
            || self::resolve($gitDirectory, $backPointer) !== realpath($root.'/.git')) {
            return null;
        }

        $common = self::resolve($gitDirectory, $commonPointer);

        if ($common === null || basename($common) !== '.git' || ! is_dir($common)
            || dirname(dirname($gitDirectory)) !== $common || basename(dirname($gitDirectory)) !== 'worktrees') {
            return null;
        }

        return dirname($common);
    }

    /** Read a bounded, regular metadata file. */
    private static function read(string $path): ?string
    {
        if (! is_file($path) || is_link($path)) {
            return null;
        }

        $contents = file_get_contents($path, false, null, 0, 4097);

        return $contents !== false && strlen($contents) <= 4096 ? trim($contents) : null;
    }

    /** Resolve an absolute or metadata-relative path. */
    private static function resolve(string $base, string $path): ?string
    {
        if ($path === '' || str_contains($path, "\0")) {
            return null;
        }

        $absolute = str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
        $resolved = realpath($absolute ? $path : $base.'/'.$path);

        return $resolved === false ? null : $resolved;
    }
}
