<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Config;

use MrKoopie\LaravelAiHarness\Environment\Runtime;
use MrKoopie\LaravelAiHarness\Environment\Services;

final readonly class Config
{
    /**
     * Store the validated harness configuration.
     *
     * @param  list<'claude'|'codex'>  $agents
     * @param  list<non-empty-string>  $sailServices
     * @param  list<non-empty-string>  $sourceFiles
     * @param  list<string>  $cloudServices
     * @param  array<string, string>  $localEnvironment
     * @param  array<string, string>  $localEnvironmentSources
     */
    public function __construct(
        public Runtime $runtime,
        public Services $serviceManager,
        public array $agents,
        public array $sailServices,
        public bool $siteSecure,
        public ?string $phpVersion,
        public bool $worktrees,
        public array $sourceFiles,
        public bool $cloud = true,
        public array $cloudServices = ['mysql'],
        public bool $cloudMigrate = false,
        public bool $cloudSeed = false,
        public bool $cloudBuild = false,
        public bool $cloudBrowser = false,
        public array $localEnvironment = [],
        public array $localEnvironmentSources = [],
    ) {}

    /** Determine whether the named coding agent is enabled. */
    public function supportsAgent(string $agent): bool
    {
        return in_array($agent, $this->agents, true);
    }

    /** Determine whether Sail owns the checkout's MySQL connection and databases. */
    public function managesMySql(): bool
    {
        return $this->serviceManager === Services::Sail && in_array('mysql', $this->sailServices, true);
    }
}
