<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Environment;

final class SiteName
{
    public static function forPath(string $root): string
    {
        $base = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', basename($root)));
        $base = trim($base, '-');
        $suffix = substr(hash('sha256', $root), 0, 10);
        $base = substr($base === '' ? 'laravel' : $base, 0, 52);
        $base = rtrim($base, '-');

        return $base.'-'.$suffix;
    }
}
