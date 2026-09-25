<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Files;

use MrKoopie\LaravelAiHarness\Config\Config;

final readonly class ProjectInstaller
{
    /** Create the project integration installer. */
    public function __construct(
        private SafeWriter $writer,
        private ClaudeSettings $claudeSettings,
    ) {}

    /** Create a default project configuration when none exists. */
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
     * Install the project-side files required by the configuration.
     *
     * @return list<string>
     */
    public function install(string $root, Config $config): array
    {
        $written = ['.ai-harness', '.gitignore'];

        $this->writer->write($root, '.ai-harness', $this->resource('project/bootstrap.sh'), executable: true);

        if ($config->cloud) {
            $this->writer->write($root, '.ai-harness-cloud', $this->resource('project/cloud.sh'), executable: true);
            $written[] = '.ai-harness-cloud';
        } else {
            $this->writer->removeOwnedFile($root, '.ai-harness-cloud', $this->resource('project/cloud.sh'));
        }

        $this->writer->managedBlock($root, '.gitignore', $this->resource('project/gitignore'));

        if ($config->supportsAgent('codex') || $config->supportsAgent('claude')) {
            $this->writer->managedBlock($root, 'AGENTS.md', $this->resource('agents/AGENTS.md'));
            $written[] = 'AGENTS.md';
        } else {
            $this->writer->removeManagedBlock($root, 'AGENTS.md');
        }

        if ($config->supportsAgent('codex') && $config->worktrees) {
            $this->writer->write($root, '.codex/environments/environment.toml', $this->resource('agents/codex-environment.toml'));
            $written[] = '.codex/environments/environment.toml';
        } else {
            $this->writer->removeOwnedFile($root, '.codex/environments/environment.toml', $this->resource('agents/codex-environment.toml'));
        }

        $this->writer->removeManagedBlock($root, 'CLAUDE.md');

        $claudeHooks = $config->supportsAgent('claude') && $config->worktrees;
        $claudeCloud = $config->supportsAgent('claude') && $config->cloud;
        $this->claudeSettings->sync($root, $claudeHooks, $claudeCloud);

        if ($claudeHooks || $claudeCloud || is_file($root.'/.claude/settings.json')) {
            $written[] = '.claude/settings.json';
        }

        return array_values(array_unique($written));
    }

    /** Read a bundled package resource. */
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
