<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Support;

use RuntimeException;

final class ProjectPath
{
    public static function resolve(?string $path = null): string
    {
        $candidate = $path === null || trim($path) === '' ? getcwd() : $path;

        if ($candidate === false) {
            throw new RuntimeException('Unable to determine the current project directory.');
        }

        $resolved = realpath($candidate);

        if ($resolved === false || ! is_dir($resolved)) {
            throw new RuntimeException("Project directory [{$candidate}] does not exist.");
        }

        $trimmed = rtrim($resolved, DIRECTORY_SEPARATOR);

        if ($trimmed === '' || preg_match('/^[A-Za-z]:[\\\\\/]$/', $resolved) === 1) {
            return $resolved;
        }

        return $trimmed;
    }
}
