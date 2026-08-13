<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Config;

use MrKoopie\LaravelAiHarness\Environment\Runtime;
use MrKoopie\LaravelAiHarness\Environment\Services;

final class ConfigLoader
{
    private const MAX_FILE_SIZE = 65_536;

    private const MAX_VALUE_LENGTH = 4_096;

    /** @var array<string, string> */
    private const DEFAULTS = [
        'runtime' => 'native',
        'services' => 'none',
        'agents' => 'codex,claude',
        'sail_services' => '',
        'herd_secure' => 'true',
        'herd_php' => '',
        'worktrees' => 'true',
    ];

    /** @var list<string> */
    private const FILES = [
        '.ai-harness.config.dist',
        '.ai-harness.config',
        '.ai-harness.config.local',
    ];

    public function load(string $root): Config
    {
        $values = self::DEFAULTS;
        $sourceFiles = [];

        foreach (self::FILES as $filename) {
            $path = $root.DIRECTORY_SEPARATOR.$filename;

            if (! is_file($path)) {
                continue;
            }

            $values = array_replace($values, $this->parseFile($path));
            $sourceFiles[] = $filename;
        }

        $runtime = Runtime::tryFrom($values['runtime']);

        if ($runtime === null) {
            throw new ConfigException('runtime must be one of: native, herd, sail.');
        }

        $services = Services::tryFrom($values['services']);

        if ($services === null) {
            throw new ConfigException('services must be one of: none, sail.');
        }

        $agents = $this->list($values['agents'], 'agents');

        foreach ($agents as $agent) {
            if (! in_array($agent, ['claude', 'codex'], true)) {
                throw new ConfigException("Unsupported agent [{$agent}]. Supported agents: codex, claude.");
            }
        }

        $sailServices = $this->list($values['sail_services'], 'sail_services');

        foreach ($sailServices as $service) {
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/', $service) !== 1) {
                throw new ConfigException("Invalid Sail service name [{$service}].");
            }
        }

        if ($services !== Services::Sail && $sailServices !== []) {
            throw new ConfigException('sail_services may only be set when services=sail.');
        }

        $herdPhp = trim($values['herd_php']);

        if ($herdPhp !== '' && preg_match('/^\d+\.\d+$/', $herdPhp) !== 1) {
            throw new ConfigException('herd_php must be empty or a major.minor version such as 8.4.');
        }

        /** @var list<'claude'|'codex'> $agents */
        return new Config(
            runtime: $runtime,
            services: $services,
            agents: $agents,
            sailServices: $sailServices,
            herdSecure: $this->boolean($values['herd_secure'], 'herd_secure'),
            herdPhp: $herdPhp === '' ? null : $herdPhp,
            worktrees: $this->boolean($values['worktrees'], 'worktrees'),
            sourceFiles: $sourceFiles,
        );
    }

    /**
     * @return array<string, string>
     */
    private function parseFile(string $path): array
    {
        $size = filesize($path);

        if ($size === false || $size > self::MAX_FILE_SIZE) {
            throw new ConfigException("Configuration file [{$path}] exceeds 64 KiB or cannot be read.");
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            throw new ConfigException("Unable to read configuration file [{$path}].");
        }

        $values = [];

        foreach ($lines as $index => $line) {
            $trimmed = trim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '#') || str_starts_with($trimmed, ';')) {
                continue;
            }

            if (! str_contains($trimmed, '=')) {
                throw new ConfigException(sprintf('Invalid configuration at %s:%d; expected key=value.', $path, $index + 1));
            }

            [$key, $rawValue] = array_map('trim', explode('=', $trimmed, 2));

            if (! array_key_exists($key, self::DEFAULTS)) {
                throw new ConfigException(sprintf('Unknown configuration key [%s] at %s:%d.', $key, $path, $index + 1));
            }

            if (array_key_exists($key, $values)) {
                throw new ConfigException(sprintf('Duplicate configuration key [%s] at %s:%d.', $key, $path, $index + 1));
            }

            $value = $this->unquote($rawValue, $path, $index + 1);

            if (strlen($value) > self::MAX_VALUE_LENGTH) {
                throw new ConfigException(sprintf('Configuration value [%s] at %s:%d exceeds 4096 bytes.', $key, $path, $index + 1));
            }

            $values[$key] = $value;
        }

        return $values;
    }

    private function unquote(string $value, string $path, int $line): string
    {
        if ($value === '') {
            return '';
        }

        $quote = $value[0];

        if ($quote !== '"' && $quote !== "'") {
            return $value;
        }

        if (strlen($value) < 2 || $value[strlen($value) - 1] !== $quote) {
            throw new ConfigException("Unterminated quoted value at {$path}:{$line}.");
        }

        return substr($value, 1, -1);
    }

    /**
     * @return list<non-empty-string>
     */
    private function list(string $value, string $key): array
    {
        if (trim($value) === '') {
            return [];
        }

        $items = array_map('trim', explode(',', $value));

        if (in_array('', $items, true)) {
            throw new ConfigException("{$key} contains an empty list item.");
        }

        return array_values(array_unique($items));
    }

    private function boolean(string $value, string $key): bool
    {
        return match (strtolower(trim($value))) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => throw new ConfigException("{$key} must be true or false."),
        };
    }
}
