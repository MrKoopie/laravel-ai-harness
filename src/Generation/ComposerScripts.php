<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Generation;

use RuntimeException;

/**
 * Maintains Composer lifecycle hooks for automatic harness refreshes.
 */
final readonly class ComposerScripts
{
    /**
     * @param  non-empty-string  $baseScript
     */
    public function __construct(private string $baseScript = 'ai-harness:update --ansi') {}

    /**
     * Ensure post-install and post-update scripts run the harness updater.
     *
     * @param  list<string>  $features
     */
    public function ensureAutoUpdateHooks(string $composerPath, array $features = []): void
    {
        $composer = $this->readComposer($composerPath);
        $script = $this->script($features);

        $composer['scripts'] ??= [];
        $composer['scripts']['post-install-cmd'] = $this->appendHarnessScript(
            $this->scriptList($composer['scripts']['post-install-cmd'] ?? []),
            $script,
        );
        $composer['scripts']['post-update-cmd'] = $this->appendHarnessScript(
            $this->scriptList($composer['scripts']['post-update-cmd'] ?? []),
            $script,
        );

        $this->writeComposer($composerPath, $composer);
    }

    /**
     * @return array<string, mixed>
     */
    private function readComposer(string $composerPath): array
    {
        if (! file_exists($composerPath)) {
            throw new RuntimeException("Composer file [{$composerPath}] does not exist.");
        }

        $contents = file_get_contents($composerPath);

        if ($contents === false) {
            throw new RuntimeException("Unable to read composer file [{$composerPath}].");
        }

        $composer = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($composer)) {
            throw new RuntimeException("Composer file [{$composerPath}] did not decode to an object.");
        }

        return $composer;
    }

    /**
     * @param  array<string, mixed>  $composer
     */
    private function writeComposer(string $composerPath, array $composer): void
    {
        $json = json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
        $temporaryPath = tempnam(dirname($composerPath), basename($composerPath).'.');

        if ($temporaryPath === false) {
            throw new RuntimeException("Unable to create temporary composer file for [{$composerPath}].");
        }

        if (file_put_contents($temporaryPath, $json) !== strlen($json)) {
            @unlink($temporaryPath);

            throw new RuntimeException("Unable to update composer file [{$composerPath}].");
        }

        if (! rename($temporaryPath, $composerPath)) {
            @unlink($temporaryPath);

            throw new RuntimeException("Unable to update composer file [{$composerPath}].");
        }
    }

    /**
     * @return list<string>
     */
    private function scriptList(mixed $scripts): array
    {
        if (is_string($scripts)) {
            return [$scripts];
        }

        if (is_array($scripts)) {
            return array_values(array_filter($scripts, is_string(...)));
        }

        return [];
    }

    /**
     * @param  list<string>  $scripts
     * @return list<string>
     */
    private function appendHarnessScript(array $scripts, string $script): array
    {
        $scripts = array_values(array_filter(
            $scripts,
            fn (string $existingScript): bool => ! str_contains($existingScript, 'ai-harness:update'),
        ));

        $scripts[] = $script;

        return $scripts;
    }

    /**
     * @param  list<string>  $features
     */
    private function script(array $features): string
    {
        $features = array_values(array_unique(array_filter(
            $features,
            fn (string $feature): bool => preg_match('/^[a-z0-9_-]+$/', $feature) === 1,
        )));

        sort($features);

        $command = $this->baseScript;

        if ($features !== []) {
            $command .= ' '.implode(' ', array_map(
                fn (string $feature): string => "--with={$feature}",
                $features,
            ));
        }

        return '@php -r "if (file_exists(\'vendor/mrkoopie/laravel-ai-harness\')) { passthru(PHP_BINARY.\' artisan '.$command.'\', $code); exit($code); }"';
    }
}
