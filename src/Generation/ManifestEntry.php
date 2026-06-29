<?php

declare(strict_types=1);

namespace Yourwebhoster\LaravelAiHarness\Generation;

/**
 * Describes one generated artifact in the harness manifest.
 */
final readonly class ManifestEntry
{
    /**
     * @param  'managed-block'|'managed-file'  $mode
     */
    public function __construct(
        public string $path,
        public string $stub,
        public string $mode,
        public bool $executable = false,
        public ?string $feature = null,
    ) {}

    /**
     * Determine whether the entry updates a block inside a user-owned file.
     */
    public function isBlock(): bool
    {
        return $this->mode === 'managed-block';
    }
}
