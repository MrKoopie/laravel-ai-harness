<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Generation;

/**
 * Provides the generated artifact list and filters feature-gated entries.
 */
final class HarnessManifest
{
    /**
     * Return manifest entries selected for the provided optional features.
     *
     * @param  list<string>  $features
     * @return list<ManifestEntry>
     */
    public function entries(array $features = []): array
    {
        $enabledFeatures = array_fill_keys($features, true);

        return array_values(array_filter($this->all(), fn (ManifestEntry $entry): bool => $entry->feature === null || isset($enabledFeatures[$entry->feature])));
    }

    /**
     * @return list<ManifestEntry>
     */
    private function all(): array
    {
        return [
            new ManifestEntry('AGENTS.md', 'root/AGENTS.md.stub', 'managed-block'),
            new ManifestEntry('CLAUDE.md', 'root/CLAUDE.md.stub', 'managed-block'),
            new ManifestEntry('.ai/mcp/mcp.json', 'ai/mcp.json.stub', 'managed-file'),
            new ManifestEntry('.dev/bin/ai-harness', 'dev/bin/ai-harness.stub', 'managed-file', executable: true),
            new ManifestEntry('.codex/config.toml.example', 'codex/config.toml.example.stub', 'managed-file', feature: 'codex'),
            new ManifestEntry('.codex/environments/environment.toml', 'codex/environments/environment.toml.stub', 'managed-file', feature: 'codex'),
            new ManifestEntry('.codex/hooks.json', 'codex/hooks.json.stub', 'managed-file', feature: 'codex'),
            new ManifestEntry('.codex/scripts/local-environment.sh', 'codex/scripts/local-environment.sh.stub', 'managed-file', executable: true, feature: 'codex'),
            new ManifestEntry('.claude/settings.json', 'claude/settings.json.stub', 'managed-file', feature: 'claude'),
            new ManifestEntry('polyscope.json', 'polyscope.json.stub', 'managed-file', feature: 'polyscope'),
            new ManifestEntry('docker/mysql/init/10-create-testing-database.sh', 'docker/mysql/init/10-create-testing-database.sh.stub', 'managed-file', executable: true, feature: 'docker'),
            new ManifestEntry('.agents/skills/laravel-ai-harness/SKILL.md', 'skills/laravel-ai-harness/SKILL.md.stub', 'managed-file', feature: 'skills'),
            new ManifestEntry('.claude/skills/laravel-ai-harness/SKILL.md', 'skills/laravel-ai-harness/SKILL.md.stub', 'managed-file', feature: 'skills'),
        ];
    }
}
