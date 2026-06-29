<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Generation;

use RuntimeException;

/**
 * Renders manifest entries into a target Laravel project.
 */
final readonly class HarnessUpdater
{
    public function __construct(
        private HarnessManifest $manifest,
        private ManagedBlockWriter $blockWriter,
        private TemplateRenderer $renderer,
    ) {}

    /**
     * Refresh selected harness files and return paths that were written.
     *
     * @param  list<string>  $features
     * @return list<string>
     */
    public function update(string $basePath, array $features = []): array
    {
        $written = [];

        foreach ($this->manifest->entries($features) as $entry) {
            $target = rtrim($basePath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$entry->path;
            $content = $this->renderer->render($this->stub($entry), $this->variables($basePath));

            if ($entry->isBlock()) {
                $this->blockWriter->write($target, $content);
            } else {
                $this->writeFile($target, $content);
            }

            if ($entry->executable && ! chmod($target, 0755)) {
                throw new RuntimeException("Unable to mark file [{$target}] as executable.");
            }

            $written[] = $entry->path;
        }

        return $written;
    }

    private function writeFile(string $target, string $content): void
    {
        $directory = dirname($target);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create directory [{$directory}].");
        }

        $content = str_ends_with($content, "\n") ? $content : $content."\n";

        if (file_put_contents($target, $content) !== strlen($content)) {
            throw new RuntimeException("Unable to write file [{$target}].");
        }
    }

    private function stub(ManifestEntry $entry): string
    {
        $path = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'stubs'.DIRECTORY_SEPARATOR.$entry->stub;

        if (! file_exists($path)) {
            throw new RuntimeException("Stub [{$entry->stub}] does not exist.");
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("Unable to read stub [{$entry->stub}].");
        }

        return $contents;
    }

    /**
     * @return array<string, string>
     */
    private function variables(string $basePath): array
    {
        $appName = $this->appName($basePath);
        $appSlug = $this->appSlug($appName);
        $database = $this->databaseName($appSlug);

        return [
            'app_name' => $appName,
            'app_slug' => $appSlug,
            'database_name' => $database,
            'testing_database_name' => $database.'_testing',
            'package_name' => 'mrkoopie/laravel-ai-harness',
            'php_version' => $this->phpVersion($basePath),
            'worktree_base_ref' => $this->worktreeBaseRef($basePath),
            'codex_status_message_json' => json_encode("Provisioning {$appName} worktree", JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ];
    }

    private function appName(string $basePath): string
    {
        $configured = config('ai-harness.project.name');

        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        $existing = $this->existingProjectContextValue($basePath, 'App');

        if ($existing !== null) {
            return $existing;
        }

        return basename($basePath);
    }

    private function appSlug(string $appName): string
    {
        $configured = config('ai-harness.project.slug');

        if (is_string($configured) && trim($configured) !== '') {
            return $this->slug(trim($configured), '-');
        }

        return $this->slug($appName, '-');
    }

    private function databaseName(string $appSlug): string
    {
        $configured = config('ai-harness.project.database_name');

        if (is_string($configured) && trim($configured) !== '') {
            return $this->slug(trim($configured), '_');
        }

        return str_replace('-', '_', $appSlug);
    }

    private function slug(string $value, string $separator): string
    {
        $slug = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', $separator, $value));

        return trim($slug, $separator);
    }

    private function existingProjectContextValue(string $basePath, string $key): ?string
    {
        $path = rtrim($basePath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'AGENTS.md';

        if (! is_readable($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("Unable to read project guidance [{$path}].");
        }

        $pattern = '/^- '.preg_quote($key, '/').':\s*(.+)$/m';

        if (preg_match($pattern, $contents, $matches) !== 1) {
            return null;
        }

        $value = trim($matches[1]);

        return $value === '' ? null : $value;
    }

    private function phpVersion(string $basePath): string
    {
        $configured = config('ai-harness.project.php_version');

        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        $constraint = $this->composerPhpConstraint($basePath);

        if ($constraint !== null) {
            $version = $this->versionFromConstraint($constraint);

            if ($version !== null) {
                return $version;
            }
        }

        return PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;
    }

    private function composerPhpConstraint(string $basePath): ?string
    {
        $composerPath = rtrim($basePath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'composer.json';

        if (! file_exists($composerPath)) {
            return null;
        }

        $contents = file_get_contents($composerPath);

        if ($contents === false) {
            throw new RuntimeException("Unable to read composer file [{$composerPath}].");
        }

        $composer = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($composer)) {
            return null;
        }

        $require = $composer['require'] ?? null;

        if (! is_array($require)) {
            return null;
        }

        $constraint = $require['php'] ?? null;

        return is_string($constraint) ? $constraint : null;
    }

    private function versionFromConstraint(string $constraint): ?string
    {
        if (preg_match('/(?:\\^|~|>=|>|=|v)?\\s*(\\d+\\.\\d+)/', $constraint, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    private function worktreeBaseRef(string $basePath): string
    {
        $configured = config('ai-harness.project.worktree_base_ref');

        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        $remoteHeadPath = rtrim($basePath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'.git'.DIRECTORY_SEPARATOR.'refs'.DIRECTORY_SEPARATOR.'remotes'.DIRECTORY_SEPARATOR.'origin'.DIRECTORY_SEPARATOR.'HEAD';

        if (is_readable($remoteHeadPath)) {
            $remoteHeadContents = file_get_contents($remoteHeadPath);

            if ($remoteHeadContents === false) {
                throw new RuntimeException("Unable to read git remote head [{$remoteHeadPath}].");
            }

            $remoteHead = trim($remoteHeadContents);

            if (preg_match('#^ref: refs/remotes/(.+)$#', $remoteHead, $matches) === 1) {
                return $matches[1];
            }
        }

        return 'origin/main';
    }
}
