<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Drivers\Agents;

use MrKoopie\LaravelAiHarness\Contracts\AgentDriver;

/**
 * Immutable agent driver backed by static guidance and skills paths.
 */
final readonly class BasicAgentDriver implements AgentDriver
{
    /**
     * @param  non-empty-string  $name
     * @param  non-empty-string  $guidelinesPath
     * @param  non-empty-string  $skillsPath
     */
    public function __construct(
        private string $name,
        private string $guidelinesPath,
        private string $skillsPath,
    ) {}

    /**
     * Return the agent driver key.
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * Return the agent guidance file path.
     */
    public function guidelinesPath(): string
    {
        return $this->guidelinesPath;
    }

    /**
     * Return the generated skills directory path.
     */
    public function skillsPath(): string
    {
        return $this->skillsPath;
    }
}
