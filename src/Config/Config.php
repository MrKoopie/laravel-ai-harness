<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Config;

use MrKoopie\LaravelAiHarness\Environment\Runtime;
use MrKoopie\LaravelAiHarness\Environment\Services;

final readonly class Config
{
    /**
     * @param  list<'claude'|'codex'>  $agents
     * @param  list<non-empty-string>  $sailServices
     * @param  list<non-empty-string>  $sourceFiles
     */
    public function __construct(
        public Runtime $runtime,
        public Services $services,
        public array $agents,
        public array $sailServices,
        public bool $herdSecure,
        public ?string $herdPhp,
        public bool $worktrees,
        public array $sourceFiles,
    ) {}

    public function supportsAgent(string $agent): bool
    {
        return in_array($agent, $this->agents, true);
    }
}
