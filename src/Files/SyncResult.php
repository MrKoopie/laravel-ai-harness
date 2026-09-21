<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Files;

final readonly class SyncResult
{
    /**
     * Store the outcome of a project-file refresh.
     *
     * @param  list<string>  $written
     */
    public function __construct(
        public bool $createdConfig,
        public array $written,
    ) {}
}
