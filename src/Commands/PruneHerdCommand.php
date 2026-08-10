<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Commands;

use MrKoopie\LaravelAiHarness\Generation\HarnessUpdater;
use MrKoopie\LaravelAiHarness\Support\HerdDatabasePruner;
use RuntimeException;

/**
 * Interactively removes Herd sites whose linked worktree paths no longer exist.
 */
class PruneHerdCommand extends HarnessCommand
{
    protected $signature = 'ai-harness:prune-herd
        {--path= : Project root used to derive managed database names}
        {--sites-path= : Override Herd\'s linked-sites directory}';

    protected $description = 'Interactively inspect and prune orphaned Laravel Herd worktree sites.';

    private const REMOVE_ALL = 'Remove Herd site and derived databases';

    private const REMOVE_SITE = 'Remove Herd site only';

    private const KEEP = 'Keep this site';

    private const QUIT = 'Stop reviewing sites';

    /**
     * Review verified dangling Herd links and apply only confirmed removals.
     */
    public function handle(HarnessUpdater $updater, HerdDatabasePruner $databasePruner): int
    {
        $projectPath = $this->projectPathOption();
        $databaseBase = $updater->databaseBaseName($projectPath);
        $orphans = $this->orphanedSites($this->sitesPath(), $projectPath, $databaseBase);

        if ($orphans === []) {
            $this->info('No orphaned AI Harness Herd sites were found.');

            return self::SUCCESS;
        }

        $this->warn(sprintf('Found %d orphaned AI Harness Herd site%s.', count($orphans), count($orphans) === 1 ? '' : 's'));

        if (! $this->input->isInteractive()) {
            foreach ($orphans as $orphan) {
                $this->renderOrphan($orphan);
            }

            $this->warn('No changes were made because the command is not running interactively.');

            return self::SUCCESS;
        }

        $herd = $this->herdBinary();
        $failed = false;

        foreach ($orphans as $orphan) {
            $this->renderOrphan($orphan);
            $choices = [self::REMOVE_SITE, self::KEEP, self::QUIT];

            if ($databasePruner->supported()) {
                array_unshift($choices, self::REMOVE_ALL);
            }

            $choice = $this->choice('What should be done with this orphan?', $choices, self::KEEP);

            if ($choice === self::QUIT) {
                break;
            }

            if ($choice === self::KEEP) {
                continue;
            }

            if (! $this->confirm('Apply this permanent cleanup?', false)) {
                $this->line('Skipped.');

                continue;
            }

            if ($herd === null) {
                $this->error('Laravel Herd CLI could not be resolved; no resources were removed.');
                $failed = true;

                continue;
            }

            if ($choice === self::REMOVE_ALL && ! $this->dropDatabases($databasePruner, $orphan['databases'])) {
                $failed = true;

                continue;
            }

            if (! $this->removeHerdSite($herd, $orphan['site'])) {
                $this->error("Unable to remove Herd site [{$orphan['site']}].");
                $failed = true;

                continue;
            }

            $this->info("Removed orphaned Herd site [{$orphan['site']}].");
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return list<array{site: string, path: string, checksum: string, databases: list<string>}>
     */
    private function orphanedSites(string $sitesPath, string $projectPath, string $databaseBase): array
    {
        if (! is_dir($sitesPath)) {
            return [];
        }

        $entries = scandir($sitesPath);

        if ($entries === false) {
            throw new RuntimeException("Unable to inspect Herd sites directory [{$sitesPath}].");
        }

        $orphans = [];

        foreach ($entries as $site) {
            if ($site === '.' || $site === '..') {
                continue;
            }

            $link = $sitesPath.DIRECTORY_SEPARATOR.$site;

            if (! is_link($link)) {
                continue;
            }

            $target = readlink($link);

            if ($target === false) {
                continue;
            }

            $target = $this->absoluteLinkTarget($link, $target);

            if (file_exists($target)) {
                continue;
            }

            $checksum = $this->pathChecksum($target);

            if ($site !== $this->herdSiteName($target, $checksum)) {
                continue;
            }

            $orphans[] = [
                'site' => $site,
                'path' => $target,
                'checksum' => $checksum,
                'databases' => $this->databaseNames(
                    $this->usesProjectDatabaseBase($target, $projectPath) ? $databaseBase : basename($target),
                    $checksum,
                ),
            ];
        }

        usort($orphans, static fn (array $left, array $right): int => $left['site'] <=> $right['site']);

        return $orphans;
    }

    /**
     * Determine whether the target was provisioned from the current project.
     */
    private function usesProjectDatabaseBase(string $target, string $projectPath): bool
    {
        if (basename($target) === basename($projectPath)) {
            return true;
        }

        $claudeWorktrees = rtrim($projectPath, DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR.'.claude'
            .DIRECTORY_SEPARATOR.'worktrees'
            .DIRECTORY_SEPARATOR;

        return str_starts_with($target, $claudeWorktrees);
    }

    /**
     * @param  array{site: string, path: string, checksum: string, databases: list<string>}  $orphan
     */
    private function renderOrphan(array $orphan): void
    {
        $this->newLine();
        $this->table(['Resource', 'Value'], [
            ['Herd site', $orphan['site']],
            ['Missing path', $orphan['path']],
            ['Verified checksum', $orphan['checksum']],
            ['Application database', $orphan['databases'][0]],
            ['Testing database', $orphan['databases'][1]],
        ]);
    }

    /**
     * Resolve Herd's linked-sites directory or the explicit test override.
     */
    private function sitesPath(): string
    {
        $configured = $this->option('sites-path');

        if (is_string($configured) && $configured !== '') {
            return rtrim($configured, DIRECTORY_SEPARATOR);
        }

        $home = getenv('HOME');

        if (! is_string($home) || $home === '') {
            throw new RuntimeException('Unable to resolve the user home directory for Laravel Herd.');
        }

        return $home.'/Library/Application Support/Herd/config/valet/Sites';
    }

    /**
     * Resolve a symlink target without requiring the target to still exist.
     */
    private function absoluteLinkTarget(string $link, string $target): string
    {
        if (str_starts_with($target, DIRECTORY_SEPARATOR)) {
            return $target;
        }

        return dirname($link).DIRECTORY_SEPARATOR.$target;
    }

    /**
     * Reproduce the provisioner's POSIX cksum value for a worktree path.
     */
    private function pathChecksum(string $path): string
    {
        $output = [];
        $status = 0;
        exec('printf %s '.escapeshellarg($path).' | cksum 2>/dev/null', $output, $status);

        if ($status !== 0 || preg_match('/^(\d+)\s+/', implode("\n", $output), $matches) !== 1) {
            throw new RuntimeException("Unable to calculate the worktree checksum for [{$path}].");
        }

        return $matches[1];
    }

    /**
     * Reproduce the deterministic Herd site name used during provisioning.
     */
    private function herdSiteName(string $path, string $checksum): string
    {
        $project = basename($path);
        $parent = basename(dirname($path));
        $base = strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $project.'-'.$parent));
        $base = trim($base, '-');
        $base = substr($base, 0, 63 - strlen($checksum) - 1);
        $base = rtrim($base, '-');

        return ($base !== '' ? $base : 'codex-worktree').'-'.$checksum;
    }

    /**
     * @return list<string>
     */
    private function databaseNames(string $databaseBase, string $checksum): array
    {
        $base = strtolower((string) preg_replace('/[^a-z0-9]+/i', '_', $databaseBase));
        $base = trim($base, '_');
        $appBase = substr($base, 0, 64 - strlen($checksum) - 1);
        $testingSuffix = '_testing_';
        $testingBase = substr($base, 0, 64 - strlen($checksum) - strlen($testingSuffix));

        return [
            $appBase !== '' ? $appBase.'_'.$checksum : 'codex_worktree_'.$checksum,
            $testingBase !== '' ? $testingBase.$testingSuffix.$checksum : 'codex_worktree_testing_'.$checksum,
        ];
    }

    /**
     * @param  list<string>  $databases
     */
    private function dropDatabases(HerdDatabasePruner $pruner, array $databases): bool
    {
        try {
            $pruner->drop($databases);

            foreach ($databases as $database) {
                $this->line("Dropped database [{$database}] when present.");
            }
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());
            $this->error('Database cleanup failed; the Herd site was kept so the orphan remains visible for a retry.');

            return false;
        }

        return true;
    }

    /**
     * Unsecure and unlink a confirmed Herd site through Herd's own CLI.
     */
    private function removeHerdSite(string $herd, string $site): bool
    {
        // Unsecure is best-effort: a site may never have been secured or Herd
        // may already have removed its certificate while retaining the link.
        [$unsecureStatus, $unsecureOutput] = $this->runProcess([$herd, 'unsecure', $site]);

        if ($unsecureStatus !== 0 && preg_match('/(not secured|not found|does not exist)/i', $unsecureOutput) !== 1) {
            $this->warn("Herd could not fully unsecure [{$site}]: {$unsecureOutput}");
        }

        [$status, $output] = $this->runProcess([$herd, 'unlink', $site]);

        if ($status === 0 || preg_match('/(not found|not linked|does not exist|no .*link)/i', $output) === 1) {
            return true;
        }

        if ($output !== '') {
            $this->error($output);
        }

        return false;
    }

    /**
     * Resolve the Herd CLI from PATH or its standard macOS installation path.
     */
    private function herdBinary(): ?string
    {
        $output = [];
        $status = 0;
        exec('command -v herd 2>/dev/null', $output, $status);

        if ($status === 0 && isset($output[0]) && is_executable($output[0])) {
            return $output[0];
        }

        $home = getenv('HOME');
        $fallback = is_string($home) ? $home.'/Library/Application Support/Herd/bin/herd' : '';

        return $fallback !== '' && is_executable($fallback) ? $fallback : null;
    }

    /**
     * @param  list<string>  $arguments
     * @return array{int, string}
     */
    private function runProcess(array $arguments): array
    {
        $output = [];
        $status = 0;
        $command = implode(' ', array_map(escapeshellarg(...), $arguments)).' 2>&1';
        exec($command, $output, $status);

        return [$status, trim(implode("\n", $output))];
    }
}
