<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Contracts;

/**
 * Describes where a coding agent reads project guidance and generated skills.
 */
interface AgentDriver
{
    /**
     * Return the stable driver key used in configuration and docs.
     */
    public function name(): string;

    /**
     * Return the root guidance file path used by this agent.
     */
    public function guidelinesPath(): string;

    /**
     * Return the directory where generated skills should be written.
     */
    public function skillsPath(): string;
}
