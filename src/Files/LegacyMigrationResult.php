<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Files;

final readonly class LegacyMigrationResult
{
    /**
     * Store the outcome of a v0.1 configuration migration.
     *
     * @param  list<string>  $warnings
     */
    public function __construct(
        public bool $created,
        public array $warnings,
    ) {}
}
