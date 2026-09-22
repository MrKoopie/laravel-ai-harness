<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Environment;

use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;

final class HerdPhp
{
    /** Resolve Herd's isolated PHP without executing warning-contaminated shell output. */
    public function resolve(string $herd, string $root): ?string
    {
        $directory = dirname(realpath($herd) ?: $herd);
        $php = $directory.'/php';
        $phar = $directory.'/herd.phar';

        if (! is_executable($php) || ! is_file($phar) || ! is_readable($phar)) {
            return null;
        }

        $process = new Process([$php, '-d', 'error_reporting=E_ALL & ~E_DEPRECATED', $phar, 'which-php'], $root);
        $process->setTimeout(10);

        try {
            $process->run();
        } catch (ExceptionInterface) {
            return null;
        }

        if (! $process->isSuccessful()) {
            return null;
        }

        $lines = preg_split('/\R/', trim($process->getOutput()));
        $candidate = is_array($lines) ? end($lines) : false;
        $resolved = is_string($candidate) ? realpath($candidate) : false;

        if ($resolved === false || dirname($resolved) !== $directory
            || preg_match('/^php[0-9]+$/D', basename($resolved)) !== 1
            || ! is_file($resolved) || ! is_executable($resolved)) {
            return null;
        }

        return $resolved;
    }
}
