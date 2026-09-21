<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Health;

final readonly class CheckResult
{
    /** Store the outcome and user-facing message for one health check. */
    public function __construct(
        public bool $passed,
        public string $message,
    ) {}
}
