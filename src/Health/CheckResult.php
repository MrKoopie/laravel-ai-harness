<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Health;

final readonly class CheckResult
{
    public function __construct(
        public bool $passed,
        public string $message,
    ) {}
}
