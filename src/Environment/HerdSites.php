<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Environment;

use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class HerdSites
{
    /** Resolve the directory Herd actually uses for links. */
    public static function directory(): string
    {
        $home = getenv('HOME');

        if (! is_string($home) || $home === '') {
            throw new EnvironmentException('Cannot locate the Herd sites directory without HOME.');
        }

        return $home.'/Library/Application Support/Herd/config/valet/Sites';
    }

    /**
     * Return verified missing targets, keyed by site name.
     *
     * @return array<string, string>
     */
    public function orphans(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $entries = scandir($directory);

        if ($entries === false) {
            throw new EnvironmentException('Cannot read Herd sites directory.');
        }

        $orphans = [];

        foreach ($entries as $site) {
            $target = $this->orphanTarget($directory, $site);

            if ($target !== null) {
                $orphans[$site] = $target;
            }
        }

        return $orphans;
    }

    /** Validate the link again without trusting a prior scan or stale stat cache. */
    public function orphanTarget(string $directory, string $site): ?string
    {
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/D', $site) !== 1) {
            return null;
        }

        $link = $directory.'/'.$site;
        clearstatcache();

        if (! is_link($link)) {
            return null;
        }

        $target = readlink($link);

        if ($target === false || file_exists($link)) {
            return null;
        }

        $target = $this->normalize(str_starts_with($target, '/') ? $target : $directory.'/'.$target);

        if (file_exists($target) || is_link($target)) {
            return null;
        }

        if ($site === SiteName::forPath($target)) {
            return $target;
        }

        // Older generated scripts used POSIX cksum, without a trailing newline.
        $binary = (new ExecutableFinder)->find('cksum');

        if ($binary === null) {
            return null;
        }

        $checksum = new Process([$binary]);
        $checksum->setInput($target);
        $checksum->setTimeout(5);
        try {
            $checksum->run();
        } catch (ExceptionInterface) {
            return null;
        }

        if (! $checksum->isSuccessful() || preg_match('/^(\d+)\s/', $checksum->getOutput(), $matches) !== 1) {
            return null;
        }

        $base = strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', basename($target).'-'.basename(dirname($target))));
        $base = rtrim(substr(trim($base, '-'), 0, 63 - strlen($matches[1]) - 1), '-');
        $legacy = ($base === '' ? 'codex-worktree' : $base).'-'.$matches[1];

        return $site === $legacy ? $target : null;
    }

    /** Normalize relative link components without requiring the deleted target to exist. */
    private function normalize(string $path): string
    {
        $parts = [];

        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }

            if ($part === '..') {
                array_pop($parts);
            } else {
                $parts[] = $part;
            }
        }

        return '/'.implode('/', $parts);
    }
}
