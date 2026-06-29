<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Contracts;

/**
 * Builds commands for running Laravel inside a local project runtime.
 */
interface RuntimeDriver
{
    /**
     * Return the stable runtime key used in configuration and docs.
     */
    public function name(): string;

    /**
     * Build an Artisan command without rewriting user-supplied arguments.
     */
    public function artisanCommand(string $arguments = ''): string;
}
