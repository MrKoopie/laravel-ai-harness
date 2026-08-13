<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Files;

use MrKoopie\LaravelAiHarness\Config\Config;

final readonly class ProjectInstaller
{
    public function __construct(
        private SafeWriter $writer,
        private ClaudeSettings $claudeSettings,
    ) {}

    public function ensureConfig(string $root): bool
    {
        foreach (['.ai-harness.config.dist', '.ai-harness.config', '.ai-harness.config.local'] as $filename) {
            if (is_file($root.DIRECTORY_SEPARATOR.$filename)) {
                return false;
            }
        }

        $this->writer->write($root, '.ai-harness.config', $this->resource('project/ai-harness.config'));

        return true;
    }

    /**
     * @return list<string>
     */
    public function install(string $root, Config $config): array
    {
        $written = ['.ai-harness', '.gitignore'];

        $this->writer->write($root, '.ai-harness', $this->resource('project/bootstrap.sh'), executable: true);
        $this->writer->managedBlock($root, '.gitignore', $this->resource('project/gitignore'));

        if ($config->supportsAgent('codex')) {
            $this->writer->managedBlock($root, 'AGENTS.md', $this->resource('agents/AGENTS.md'));
            $written[] = 'AGENTS.md';

            if ($config->worktrees) {
                $this->writer->write($root, '.codex/environments/environment.toml', $this->resource('agents/codex-environment.toml'));
                $written[] = '.codex/environments/environment.toml';
            } else {
                $this->writer->removeOwnedFile($root, '.codex/environments/environment.toml', $this->resource('agents/codex-environment.toml'));
            }
        } else {
            $this->writer->removeManagedBlock($root, 'AGENTS.md');
            $this->writer->removeOwnedFile($root, '.codex/environments/environment.toml', $this->resource('agents/codex-environment.toml'));
        }

        if ($config->supportsAgent('claude')) {
            $this->writer->managedBlock($root, 'CLAUDE.md', $this->resource('agents/CLAUDE.md'));
            $written[] = 'CLAUDE.md';
        } else {
            $this->writer->removeManagedBlock($root, 'CLAUDE.md');
        }

        $claudeHooks = $config->supportsAgent('claude') && $config->worktrees;
        $this->claudeSettings->sync($root, $claudeHooks);

        if ($claudeHooks || is_file($root.'/.claude/settings.json')) {
            $written[] = '.claude/settings.json';
        }

        return array_values(array_unique($written));
    }

    private function resource(string $relativePath): string
    {
        $path = dirname(__DIR__, 2).'/resources/'.$relativePath;
        $contents = is_file($path) ? file_get_contents($path) : false;

        if ($contents === false) {
            throw new FileException("Unable to read package resource [{$path}].");
        }

        return $contents;
    }
}
