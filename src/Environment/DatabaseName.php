<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Environment;

final class DatabaseName
{
    /** Derive the current checkout's primary database name. */
    public static function forPath(string $root): string
    {
        return self::nameForPath($root, 32);
    }

    /** Derive the database name used by earlier harness versions. */
    public static function legacyForPath(string $root): string
    {
        return self::nameForPath($root, 45);
    }

    /** Derive a bounded, checkout-specific database name. */
    private static function nameForPath(string $root, int $maxBaseLength): string
    {
        $base = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '_', basename($root)));
        $base = trim($base, '_');
        $suffix = substr(hash('sha256', $root), 0, 10);
        $base = substr($base === '' ? 'laravel' : $base, 0, $maxBaseLength);
        $base = rtrim($base, '_');

        return $base.'_'.$suffix;
    }

    /** Derive the current checkout's testing database name. */
    public static function testingForPath(string $root): string
    {
        return self::forPath($root).'_testing';
    }
}
