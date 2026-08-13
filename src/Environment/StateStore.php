<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Environment;

use JsonException;
use MrKoopie\LaravelAiHarness\Files\FileException;
use MrKoopie\LaravelAiHarness\Files\SafeWriter;

final readonly class StateStore
{
    private const MAX_FILE_SIZE = 16_384;

    private const STATE_FILE = '.ai-harness.state.json';

    public function __construct(private SafeWriter $writer) {}

    public function herdSite(string $root): ?string
    {
        $state = $this->read($root);
        $site = $state['herd_site'] ?? null;

        return is_string($site) && $site !== '' ? $site : null;
    }

    public function recordHerdSite(string $root, string $site): void
    {
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/', $site) !== 1) {
            throw new EnvironmentException("Refusing to record invalid Herd site [{$site}].");
        }

        $state = $this->read($root);
        $state['herd_site'] = $site;
        $this->write($root, $state);
    }

    public function herdSecured(string $root): bool
    {
        return ($this->read($root)['herd_secured'] ?? false) === true;
    }

    public function recordHerdSecured(string $root): void
    {
        $state = $this->read($root);

        if (! is_string($state['herd_site'] ?? null)) {
            throw new EnvironmentException('Refusing to record Herd TLS state without a harness-owned Herd site.');
        }

        $state['herd_secured'] = true;
        $this->write($root, $state);
    }

    public function clearHerdSecured(string $root): void
    {
        $state = $this->read($root);
        unset($state['herd_secured']);
        $this->write($root, $state);
    }

    public function clearHerdSite(string $root): void
    {
        $state = $this->read($root);
        unset($state['herd_site'], $state['herd_secured']);
        $path = $root.'/'.self::STATE_FILE;

        if ($state !== []) {
            $this->write($root, $state);

            return;
        }

        if (is_link($path)) {
            throw new FileException("Refusing to remove symbolic link [{$path}].");
        }

        if (is_file($path) && ! unlink($path)) {
            throw new FileException("Unable to remove state file [{$path}].");
        }
    }

    /** @return array<string, mixed> */
    private function read(string $root): array
    {
        $path = $root.'/'.self::STATE_FILE;

        if (is_link($path)) {
            throw new FileException("Refusing to read symbolic link [{$path}].");
        }

        if (! is_file($path)) {
            return [];
        }

        $size = filesize($path);

        if ($size === false || $size > self::MAX_FILE_SIZE) {
            throw new FileException("State file [{$path}] exceeds 16 KiB or cannot be read.");
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new FileException("Unable to read state file [{$path}].");
        }

        try {
            $state = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new FileException('Invalid AI Harness state: '.$exception->getMessage(), previous: $exception);
        }

        if (! is_array($state) || ($state !== [] && array_is_list($state))) {
            throw new FileException("State file [{$path}] must contain a JSON object.");
        }

        return $state;
    }

    /** @param array<string, mixed> $state */
    private function write(string $root, array $state): void
    {
        try {
            $encoded = json_encode((object) $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new FileException('Unable to encode AI Harness state: '.$exception->getMessage(), previous: $exception);
        }

        $this->writer->write($root, self::STATE_FILE, $encoded);
    }
}
