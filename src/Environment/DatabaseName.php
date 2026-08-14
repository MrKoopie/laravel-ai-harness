<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Environment;

final class DatabaseName
{
    public static function forPath(string $root): string
    {
        $base = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '_', basename($root)));
        $base = trim($base, '_');
        $suffix = substr(hash('sha256', $root), 0, 10);
        $base = substr($base === '' ? 'laravel' : $base, 0, 45);
        $base = rtrim($base, '_');

        return $base.'_'.$suffix;
    }

    public static function testingForPath(string $root): string
    {
        return self::forPath($root).'_testing';
    }
}
