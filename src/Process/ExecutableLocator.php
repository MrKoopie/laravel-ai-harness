<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Process;

use Symfony\Component\Process\ExecutableFinder;

final readonly class ExecutableLocator
{
    private ExecutableFinder $finder;

    /** @var array<string, string|null> */
    private array $overrides;

    /** @param array<string, string|null> $overrides */
    public function __construct(?ExecutableFinder $finder = null, array $overrides = [])
    {
        $this->finder = $finder ?? new ExecutableFinder;
        $this->overrides = $overrides;
    }

    public function find(string $name): ?string
    {
        if (array_key_exists($name, $this->overrides)) {
            return $this->overrides[$name];
        }

        $path = $this->finder->find($name);

        return is_string($path) ? $path : null;
    }

    public function php(): ?string
    {
        if (array_key_exists('php', $this->overrides)) {
            return $this->overrides['php'];
        }

        if (is_executable(PHP_BINARY)) {
            return PHP_BINARY;
        }

        return $this->find('php');
    }

    public function composer(): ?string
    {
        if (array_key_exists('composer', $this->overrides)) {
            return $this->overrides['composer'];
        }

        return $this->find('composer');
    }

    public function herd(): ?string
    {
        if (array_key_exists('herd', $this->overrides)) {
            return $this->overrides['herd'];
        }

        $path = $this->find('herd');

        if ($path !== null) {
            return $path;
        }

        if (PHP_OS_FAMILY !== 'Darwin') {
            return null;
        }

        $home = getenv('HOME');

        if (! is_string($home) || $home === '') {
            return null;
        }

        $candidate = $home.'/Library/Application Support/Herd/bin/herd';

        return is_executable($candidate) ? $candidate : null;
    }
}
