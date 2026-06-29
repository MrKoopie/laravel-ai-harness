<?php

declare(strict_types=1);

namespace Yourwebhoster\LaravelAiHarness\Drivers\Runtimes;

use Yourwebhoster\LaravelAiHarness\Contracts\RuntimeDriver;

/**
 * Runtime driver that prefixes Artisan with a local command runner.
 */
final readonly class BasicRuntimeDriver implements RuntimeDriver
{
    /**
     * @param  non-empty-string  $name
     */
    public function __construct(
        private string $name,
        private string $prefix,
    ) {}

    /**
     * Return the runtime driver key.
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * Build the Artisan command while preserving quoted argument spacing.
     */
    public function artisanCommand(string $arguments = ''): string
    {
        $parts = [trim($this->prefix), 'artisan'];

        if (trim($arguments) !== '') {
            $parts[] = trim($arguments);
        }

        return implode(' ', array_filter($parts, static fn (string $part): bool => $part !== ''));
    }
}
