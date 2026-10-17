<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Config;

use MrKoopie\LaravelAiHarness\Environment\Runtime;

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
     * @param  array<string, string>  $sources
     */
    public function __construct(
        public Runtime $runtime,
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
        public array $sources = [],
        public string $dbConnection = 'auto',
    ) {}

    /** Return string values suitable for every agent's command environment.
     * @return array<string, string>
     */
    public function environment(): array
    {
        $values = [
            'runtime' => $this->runtime->value,
            'agents' => implode(',', $this->agents),
            'sail_services' => implode(',', $this->sailServices),
            'site_secure' => $this->siteSecure ? 'true' : 'false',
            'php_version' => $this->phpVersion ?? '',
            'db_connection' => $this->dbConnection,
            'worktrees' => $this->worktrees ? 'true' : 'false',
            'cloud' => $this->cloud ? 'true' : 'false',
            'cloud_services' => implode(',', $this->cloudServices),
            'cloud_migrate' => $this->cloudMigrate ? 'true' : 'false',
            'cloud_seed' => $this->cloudSeed ? 'true' : 'false',
            'cloud_build' => $this->cloudBuild ? 'true' : 'false',
            'cloud_browser' => $this->cloudBrowser ? 'true' : 'false',
        ];
        $environment = [];
        foreach (ConfigLoader::ENVIRONMENT as $variable => $key) {
            $environment[$variable] = $values[$key];
        }
        $environment['AI_HARNESS_LOCAL_ENV'] = json_encode((object) $this->localEnvironment, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return $environment;
    }

    /** Identify command/process selections that must reach new agent sessions. */
    public function hasOverrides(): bool
    {
        foreach ([...$this->sources, ...$this->localEnvironmentSources] as $source) {
            if ($source === 'command option' || str_starts_with($source, 'AI_HARNESS_')) {
                return true;
            }
        }

        return false;
    }

    /** Determine whether the named coding agent is enabled. */
    public function supportsAgent(string $agent): bool
    {
        return in_array($agent, $this->agents, true);
    }

    /** Infer local container usage without a separate service-manager selection. */
    public function usesSail(): bool
    {
        return $this->runtime === Runtime::Sail || $this->sailServices !== [];
    }

    /** Determine whether Sail owns the checkout's MySQL connection and databases. */
    public function managesMySql(): bool
    {
        return in_array('mysql', $this->sailServices, true)
            || ($this->runtime === Runtime::Sail && $this->sailServices === [] && $this->dbConnection === 'mysql');
    }

    /** Use an existing host MySQL service without starting or stopping it. */
    public function usesHostMySql(): bool
    {
        return $this->dbConnection === 'mysql' && ! $this->managesMySql();
    }
}
