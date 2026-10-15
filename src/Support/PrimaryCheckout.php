<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Support;

final class PrimaryCheckout
{
    /** Map a project, including a nested application, to its primary checkout using verified Git metadata. */
    public static function forWorktree(string $root): ?string
    {
        $project = realpath($root);

        if ($project === false || ! is_dir($project)) {
            return null;
        }

        $checkout = $project;

        // Stop at the nearest repository boundary, including independent nested repositories.
        while (! file_exists($checkout.'/.git') && ! is_link($checkout.'/.git')) {
            $parent = dirname($checkout);

            if ($parent === $checkout) {
                return null;
            }

            $checkout = $parent;
        }

        $pointer = self::read($checkout.'/.git');

        if ($pointer === null || ! str_starts_with($pointer, 'gitdir: ')) {
            return null;
        }

        $gitDirectory = self::resolve($checkout, substr($pointer, 8));

        if ($gitDirectory === null) {
            return null;
        }

        $commonPointer = self::read($gitDirectory.'/commondir');
        $backPointer = self::read($gitDirectory.'/gitdir');

        if ($commonPointer === null || $backPointer === null
            || self::resolve($gitDirectory, $backPointer) !== realpath($checkout.'/.git')) {
            return null;
        }

        $common = self::resolve($gitDirectory, $commonPointer);

        if ($common === null || basename($common) !== '.git' || ! is_dir($common)
            || dirname(dirname($gitDirectory)) !== $common || basename(dirname($gitDirectory)) !== 'worktrees') {
            return null;
        }

        $primary = dirname($common);
        $relative = ltrim(substr($project, strlen($checkout)), DIRECTORY_SEPARATOR);
        $mapped = realpath($primary.($relative === '' ? '' : DIRECTORY_SEPARATOR.$relative));

        // A corresponding application symlink must not import settings from outside the primary tree.
        if ($mapped === false || ! is_dir($mapped)
            || ($mapped !== $primary && ! str_starts_with($mapped, $primary.DIRECTORY_SEPARATOR))) {
            return null;
        }

        return $mapped;
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
