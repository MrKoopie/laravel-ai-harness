<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Drivers;

use InvalidArgumentException;
use MrKoopie\LaravelAiHarness\Contracts\AgentDriver;
use MrKoopie\LaravelAiHarness\Contracts\RuntimeDriver;
use MrKoopie\LaravelAiHarness\Drivers\Agents\BasicAgentDriver;
use MrKoopie\LaravelAiHarness\Drivers\Runtimes\BasicRuntimeDriver;

/**
 * Registry for built-in and future agent/runtime drivers.
 */
final readonly class DriverRegistry
{
    /**
     * @param  array<string, AgentDriver>  $agents
     * @param  array<string, RuntimeDriver>  $runtimes
     */
    public function __construct(
        private array $agents,
        private array $runtimes,
    ) {}

    /**
     * Build the package's default driver set.
     */
    public static function defaults(): self
    {
        return new self(
            agents: [
                'claude' => new BasicAgentDriver('claude', 'CLAUDE.md', '.claude/skills'),
                'codex' => new BasicAgentDriver('codex', 'AGENTS.md', '.agents/skills'),
                'cursor' => new BasicAgentDriver('cursor', 'AGENTS.md', '.cursor/skills'),
            ],
            runtimes: [
                'bare' => new BasicRuntimeDriver('bare', 'php'),
                'herd' => new BasicRuntimeDriver('herd', 'herd php'),
                'sail' => new BasicRuntimeDriver('sail', './vendor/bin/sail'),
            ],
        );
    }

    /**
     * Return enabled agent driver names in stable order.
     *
     * @return list<string>
     */
    public function agentNames(): array
    {
        $names = array_keys($this->agents);
        sort($names);

        return $names;
    }

    /**
     * Return enabled runtime driver names in stable order.
     *
     * @return list<string>
     */
    public function runtimeNames(): array
    {
        $names = array_keys($this->runtimes);
        sort($names);

        return $names;
    }

    /**
     * Resolve an agent driver by name.
     */
    public function agent(string $name): AgentDriver
    {
        return $this->agents[$name] ?? throw new InvalidArgumentException("Unknown agent driver [{$name}].");
    }

    /**
     * Resolve a runtime driver by name.
     */
    public function runtime(string $name): RuntimeDriver
    {
        return $this->runtimes[$name] ?? throw new InvalidArgumentException("Unknown runtime driver [{$name}].");
    }
}
