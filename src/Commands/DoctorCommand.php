<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Commands;

use MrKoopie\LaravelAiHarness\Drivers\DriverRegistry;
use MrKoopie\LaravelAiHarness\Generation\HarnessManifest;
use MrKoopie\LaravelAiHarness\Generation\HarnessUpdater;
use MrKoopie\LaravelAiHarness\Generation\ManifestEntry;
use RuntimeException;

/**
 * Reports missing generated artifacts and available drivers.
 */
class DoctorCommand extends HarnessCommand
{
    protected $signature = 'ai-harness:doctor
        {--path= : Project root to inspect}
        {--with=* : Optional feature to include for this run, such as herd, docker, or polyscope}';

    protected $description = 'Inspect Laravel AI Harness generated files and available drivers.';

    /**
     * Inspect selected manifest entries for the target project.
     */
    public function handle(DriverRegistry $drivers, HarnessManifest $manifest, HarnessUpdater $updater): int
    {
        $path = $this->projectPathOption();
        $features = $this->enabledFeatures();
        $missing = [];
        $drifted = [];
        $uncommitted = [];

        foreach ($manifest->entries($features) as $entry) {
            $target = rtrim($path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$entry->path;

            if (! file_exists($target)) {
                $missing[] = $entry->path;

                continue;
            }

            if ($this->entryDrifted($entry, $target, $updater->render($entry, $path, $features))) {
                $drifted[] = $entry->path;
            }

            if ($this->gitHasUncommittedChanges($path, $entry->path)) {
                $uncommitted[] = $entry->path;
            }
        }

        $this->line('Agents: '.implode(', ', $drivers->agentNames()));
        $this->line('Runtimes: '.implode(', ', $drivers->runtimeNames()));

        if ($missing !== [] || $drifted !== [] || $uncommitted !== []) {
            foreach ($missing as $file) {
                $this->warn("missing {$file}");
            }

            if ($drifted !== [] || $uncommitted !== []) {
                $this->warn('harness files drifted - commit them');
            }

            foreach ($drifted as $file) {
                $this->warn("drifted {$file}");
            }

            foreach ($uncommitted as $file) {
                $this->warn("uncommitted {$file}");
            }

            return self::FAILURE;
        }

        $this->info('AI harness looks healthy.');

        return self::SUCCESS;
    }

    private function entryDrifted(ManifestEntry $entry, string $target, string $expected): bool
    {
        $actual = file_get_contents($target);

        if ($actual === false) {
            throw new RuntimeException("Unable to read generated file [{$target}].");
        }

        if (! $entry->isBlock()) {
            return $this->normalizeLineEndings($actual) !== $this->normalizeLineEndings($expected);
        }

        $content = $this->managedBlockContent($entry->path, $actual);

        if ($content === null) {
            return true;
        }

        return trim($this->normalizeLineEndings($content)) !== trim($this->normalizeLineEndings($expected));
    }

    private function managedBlockContent(string $path, string $actual): ?string
    {
        $start = $this->startMarker($path);
        $end = $this->endMarker($path);
        $pattern = sprintf('/%s\\R?(.*?)\\R?%s/s', preg_quote($start, '/'), preg_quote($end, '/'));

        if (preg_match($pattern, $actual, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    private function startMarker(string $path): string
    {
        if ($this->usesHashComments($path)) {
            return '# ai-harness:start';
        }

        return '<!-- ai-harness:start -->';
    }

    private function endMarker(string $path): string
    {
        if ($this->usesHashComments($path)) {
            return '# ai-harness:end';
        }

        return '<!-- ai-harness:end -->';
    }

    private function usesHashComments(string $path): bool
    {
        return basename($path) === '.gitignore' || str_ends_with($path, '.toml');
    }

    private function normalizeLineEndings(string $content): string
    {
        return str_replace(["\r\n", "\r"], "\n", $content);
    }

    private function gitHasUncommittedChanges(string $path, string $file): bool
    {
        $inside = [];
        exec('git -C '.escapeshellarg($path).' rev-parse --is-inside-work-tree 2>/dev/null', $inside, $insideStatus);

        if ($insideStatus !== 0 || trim(implode("\n", $inside)) !== 'true') {
            return false;
        }

        $status = [];
        exec('git -C '.escapeshellarg($path).' status --porcelain -- '.escapeshellarg($file).' 2>/dev/null', $status, $statusCode);

        if ($statusCode !== 0) {
            return false;
        }

        return trim(implode("\n", $status)) !== '';
    }
}
