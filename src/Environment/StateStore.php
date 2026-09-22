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

    /** Create a state store backed by safe writes. */
    public function __construct(private SafeWriter $writer) {}

    /** Return the Herd site recorded as owned by the harness. */
    public function herdSite(string $root): ?string
    {
        $state = $this->read($root);
        $site = $state['herd_site'] ?? null;

        return is_string($site) && $site !== '' ? $site : null;
    }

    /** Record the Herd site created for a project. */
    public function recordHerdSite(string $root, string $site): void
    {
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/', $site) !== 1) {
            throw new EnvironmentException("Refusing to record invalid Herd site [{$site}].");
        }

        $state = $this->read($root);
        $state['herd_site'] = $site;
        $this->write($root, $state);
    }

    /** Determine whether the recorded Herd site has harness-managed TLS. */
    public function herdSecured(string $root): bool
    {
        return ($this->read($root)['herd_secured'] ?? false) === true;
    }

    /** Record that the harness secured the owned Herd site. */
    public function recordHerdSecured(string $root): void
    {
        $state = $this->read($root);

        if (! is_string($state['herd_site'] ?? null)) {
            throw new EnvironmentException('Refusing to record Herd TLS state without a harness-owned Herd site.');
        }

        $state['herd_secured'] = true;
        $this->write($root, $state);
    }

    /** Clear the harness-managed Herd TLS marker. */
    public function clearHerdSecured(string $root): void
    {
        $state = $this->read($root);
        unset($state['herd_secured']);
        $this->write($root, $state);
    }

    /** Clear all recorded state for the owned Herd site. */
    public function clearHerdSite(string $root): void
    {
        $state = $this->read($root);
        unset($state['herd_site'], $state['herd_secured']);

        $this->writeOrRemove($root, $state);
    }

    /** Determine whether checkout-specific MySQL databases are owned. */
    public function ownsMySqlDatabases(string $root): bool
    {
        return ($this->read($root)['mysql_databases'] ?? false) === true;
    }

    /** Record ownership of checkout-specific MySQL databases. */
    public function recordMySqlDatabases(string $root): void
    {
        $state = $this->read($root);
        $state['mysql_databases'] = true;
        $this->write($root, $state);
    }

    /** Clear ownership of checkout-specific MySQL databases. */
    public function clearMySqlDatabases(string $root): void
    {
        $state = $this->read($root);
        unset($state['mysql_databases']);

        $this->writeOrRemove($root, $state);
    }

    /** Check cloud ownership against the current checkout, including after a move. */
    public function ownsCloudTesting(string $root): bool
    {
        $name = $this->read($root)['cloud_testing_database'] ?? null;

        if ($name !== null && $name !== DatabaseName::testingForPath($root)) {
            throw new EnvironmentException('Cloud database ownership does not match this checkout.');
        }

        return $name !== null;
    }

    /** Record the exact testing database; retain this marker for retryable cleanup. */
    public function recordCloudTesting(string $root): void
    {
        $this->ownsCloudTesting($root);
        $state = $this->read($root);
        $state['cloud_testing_database'] = DatabaseName::testingForPath($root);
        $this->write($root, $state);
    }

    /**
     * Persist non-empty state or remove the empty state file.
     *
     * @param  array<string, mixed>  $state
     */
    private function writeOrRemove(string $root, array $state): void
    {
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

    /**
     * Read and validate the project's harness state.
     *
     * @return array<string, mixed>
     */
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

    /**
     * Encode and persist the project's harness state.
     *
     * @param  array<string, mixed>  $state
     */
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
