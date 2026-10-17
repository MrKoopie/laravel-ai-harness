<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Files;

use MrKoopie\LaravelAiHarness\Config\Config;
use MrKoopie\LaravelAiHarness\Support\GlobalInstallation;

final readonly class GlobalProjectInstaller
{
    /** @var list<string> */
    private const PATHS = [
        '.ai-harness', '.ai-harness-cloud', GlobalInstallation::CONFIG_FILE,
        '.ai-harness.config.local', '.ai-harness.state.json', '.env.testing',
        'AGENTS.override.md', 'CLAUDE.local.md',
        GlobalInstallation::CODEX_ENVIRONMENT, '.claude/settings.local.json',
    ];

    public function __construct(private SafeWriter $writer, private ClaudeSettings $claudeSettings) {}

    /**
     * Exclude files before creating them and reject tracked personal settings.
     *
     * @return list<string>
     */
    public function prepare(string $root): array
    {
        return (new GitExcludes($this->writer))->sync($root, self::PATHS);
    }

    /**
     * Install personal agent integrations while leaving shared project files untouched.
     *
     * @return list<string>
     */
    public function install(string $root, Config $config): array
    {
        $tracked = $this->prepare($root);
        $written = [];

        if (! in_array('.ai-harness', $tracked, true)) {
            $this->writer->write($root, '.ai-harness', $this->resource('project/global-bootstrap.sh'), executable: true);
            $written[] = '.ai-harness';
        }

        if (! in_array('.ai-harness-cloud', $tracked, true)) {
            if ($config->cloud) {
                $this->writer->write($root, '.ai-harness-cloud', $this->cloudBootstrap(), executable: true);
                $written[] = '.ai-harness-cloud';
            } else {
                $this->writer->removeOwnedFile($root, '.ai-harness-cloud', $this->cloudBootstrap());
            }
        }

        $instructions = str_replace('./.ai-harness', 'ai-harness', $this->resource('agents/AGENTS.md'));

        if ($config->supportsAgent('codex')) {
            // Codex reads the override instead of AGENTS.md, so retain the shared guidance in it.
            $shared = is_file($root.'/AGENTS.md') ? file_get_contents($root.'/AGENTS.md') : '';
            if ($shared === false) {
                throw new FileException('Unable to read shared AGENTS.md instructions.');
            }
            $shared = preg_replace('/<!-- ai-harness:start -->.*?<!-- ai-harness:end -->/s', '', $shared) ?? $shared;
            $this->writer->managedBlock($root, 'AGENTS.override.md', trim($shared)."\n\n".$instructions);
            $written[] = 'AGENTS.override.md';
        } else {
            $this->writer->removeManagedBlock($root, 'AGENTS.override.md');
        }

        if ($config->supportsAgent('claude')) {
            $shared = is_file($root.'/AGENTS.md') ? "@AGENTS.md\n\n" : '';
            $this->writer->managedBlock($root, 'CLAUDE.local.md', $shared.$instructions);
            $written[] = 'CLAUDE.local.md';
        } else {
            $this->writer->removeManagedBlock($root, 'CLAUDE.local.md');
        }

        if ($config->supportsAgent('codex') && $config->worktrees) {
            if (! CodexConfiguration::configured($root, GlobalInstallation::CODEX_ENVIRONMENT)) {
                $this->writer->write($root, GlobalInstallation::CODEX_ENVIRONMENT, $this->resource('agents/codex-global-environment.toml'));
            }
            $written[] = GlobalInstallation::CODEX_ENVIRONMENT;
        } else {
            $this->writer->removeOwnedFile($root, GlobalInstallation::CODEX_ENVIRONMENT, $this->resource('agents/codex-global-environment.toml'));
        }

        $hooks = $config->supportsAgent('claude') && $config->worktrees;
        $cloud = $config->supportsAgent('claude') && $config->cloud;
        $this->claudeSettings->sync($root, $hooks, $cloud, local: true);
        if ($config->supportsAgent('claude') && $config->hasOverrides()) {
            $this->claudeSettings->environment($root, $config->environment(), local: true);
        }

        if ($hooks || $cloud || is_file($root.'/.claude/settings.local.json')) {
            $written[] = '.claude/settings.local.json';
        }

        return $written;
    }

    /** Keep global cloud entrypoints independent of any shared project launcher. */
    private function cloudBootstrap(): string
    {
        return str_replace('exec "$project_root/.ai-harness" cloud "$@"', 'exec ai-harness cloud "$@"', $this->resource('project/cloud.sh'));
    }

    private function resource(string $path): string
    {
        $contents = file_get_contents(dirname(__DIR__, 2).'/resources/'.$path);

        if ($contents === false) {
            throw new FileException("Unable to read package resource [{$path}].");
        }

        return $contents;
    }
}
