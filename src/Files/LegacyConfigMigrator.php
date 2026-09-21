<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Files;

final readonly class LegacyConfigMigrator
{
    /** @var list<string> */
    private const CONFIG_FILES = [
        '.ai-harness.config.dist',
        '.ai-harness.config',
        '.ai-harness.config.local',
    ];

    /** @var list<string> */
    private const OBSOLETE_ARTIFACTS = [
        '.codex/hooks.json',
        '.codex/config.toml',
        '.codex/scripts/local-environment.sh',
        '.claude/scripts/worktree-up.sh',
        '.claude/scripts/worktree-down.sh',
        '.agents/skills/laravel-ai-harness/SKILL.md',
        '.claude/skills/laravel-ai-harness/SKILL.md',
        '.dev/bin/ai-harness',
        '.ai/mcp/mcp.json',
    ];

    /** Create the one-release legacy configuration migrator. */
    public function __construct(private SafeWriter $writer) {}

    /**
     * Translate v0.1 settings without overriding the new layered configuration.
     *
     * @param  array<string, mixed>  $features
     * @param  array<string, mixed>  $project
     * @param  list<string>  $with
     */
    public function migrate(string $root, array $features, array $project, array $with): LegacyMigrationResult
    {
        $warnings = $this->artifactWarnings($root);

        foreach (self::CONFIG_FILES as $filename) {
            if (is_file($root.'/'.$filename)) {
                return new LegacyMigrationResult(false, $warnings);
            }
        }

        foreach ($with as $feature) {
            if (in_array($feature, ['codex', 'claude', 'herd', 'docker', 'skills', 'polyscope'], true)) {
                $features[$feature] = true;
            }
        }

        $agents = [];

        foreach (['codex', 'claude'] as $agent) {
            if ($this->enabled($features, $agent, true)) {
                $agents[] = $agent;
            }
        }

        $herd = $this->enabled($features, 'herd');
        $docker = $this->enabled($features, 'docker');
        $phpVersion = is_string($project['php_version'] ?? null) ? trim($project['php_version']) : '';

        if (preg_match('/^\d+\.\d+$/', $phpVersion) !== 1) {
            $phpVersion = '';
        }

        $contents = implode("\n", [
            '# Migrated from Laravel AI Harness v0.1 settings.',
            'runtime='.($herd ? 'herd' : 'native'),
            'services='.($docker ? 'sail' : 'none'),
            'agents='.implode(',', $agents),
            'sail_services='.($docker ? 'mysql' : ''),
            'herd_secure=true',
            'herd_php='.$phpVersion,
            'worktrees=true',
        ]);

        $this->writer->write($root, '.ai-harness.config', $contents);

        if ($docker) {
            $warnings[] = 'Legacy docker support was mapped to services=sail with sail_services=mysql; review .ai-harness.config.';
        }

        foreach (['skills', 'polyscope'] as $unsupported) {
            if ($this->enabled($features, $unsupported)) {
                $warnings[] = "Legacy {$unsupported} generation is no longer managed; review existing project files.";
            }
        }

        return new LegacyMigrationResult(true, $warnings);
    }

    /**
     * Read one boolean-like legacy feature with a default.
     *
     * @param  array<string, mixed>  $features
     */
    private function enabled(array $features, string $feature, bool $default = false): bool
    {
        $value = $features[$feature] ?? $default;

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }

    /** @return list<string> */
    private function artifactWarnings(string $root): array
    {
        $warnings = [];

        foreach (self::OBSOLETE_ARTIFACTS as $path) {
            if (is_file($root.'/'.$path) || is_link($root.'/'.$path)) {
                $warnings[] = "Legacy package artifact [{$path}] remains; review and remove it if it has no project changes.";
            }
        }

        return $warnings;
    }
}
