<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Environment;

use JsonException;

final class ValetTld
{
    private const DEFAULT = 'test';

    private const MAX_FILE_SIZE = 65_536;

    /** Read the configured Valet TLD from the Valet configuration of the current user. */
    public static function current(): string
    {
        $home = getenv('HOME');

        if (! is_string($home) || $home === '') {
            return self::DEFAULT;
        }

        $path = $home.'/.config/valet/config.json';

        if (! is_file($path)) {
            return self::DEFAULT;
        }

        $size = filesize($path);

        if ($size === false || $size > self::MAX_FILE_SIZE) {
            throw new EnvironmentException("Valet configuration [{$path}] exceeds 64 KiB or cannot be read.");
        }

        try {
            $config = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new EnvironmentException("Invalid Valet configuration [{$path}]: ".$exception->getMessage(), previous: $exception);
        }

        $tld = is_array($config) ? ($config['tld'] ?? null) : null;

        if ($tld === null) {
            return self::DEFAULT;
        }

        if (! is_string($tld) || preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/', $tld) !== 1) {
            throw new EnvironmentException("Refusing to use invalid Valet TLD from [{$path}].");
        }

        return $tld;
    }
}
