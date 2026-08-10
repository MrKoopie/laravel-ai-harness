<?php

use MrKoopie\LaravelAiHarness\Support\HerdDatabasePruner;
use Symfony\Component\Process\Process;

test('herd pruner interactively removes a verified orphan site', function (): void {
    $fixture = herd_prune_fixture();

    try {
        pending_artisan('ai-harness:prune-herd', [
            '--path' => $fixture['project'],
            '--sites-path' => $fixture['sites'],
        ])
            ->expectsOutputToContain('Found 1 orphaned AI Harness Herd site.')
            ->expectsOutputToContain($fixture['site'])
            ->expectsOutputToContain($fixture['missing_path'])
            ->expectsChoice('What should be done with this orphan?', 'Remove Herd site only', [
                'Remove Herd site only',
                'Keep this site',
                'Stop reviewing sites',
            ])
            ->expectsConfirmation('Apply this permanent cleanup?', 'yes')
            ->assertSuccessful();

        expect(file_get_contents($fixture['herd_log']))
            ->toContain("unsecure {$fixture['site']}")
            ->toContain("unlink {$fixture['site']}");
    } finally {
        putenv('PATH='.$fixture['original_path']);
        putenv('HERD_PRUNE_LOG');
    }
});

test('herd pruner can remove the databases derived from the worktree checksum', function (): void {
    $fixture = herd_prune_fixture();
    $checksum = prune_path_checksum($fixture['missing_path']);
    $databasePruner = new class extends HerdDatabasePruner
    {
        /** @var list<string> */
        public array $dropped = [];

        public function supported(): bool
        {
            return true;
        }

        public function drop(array $databases): void
        {
            $this->dropped = $databases;
        }
    };

    config([
        'database.default' => 'mysql',
        'database.connections.mysql.driver' => 'mysql',
    ]);

    app()->instance(HerdDatabasePruner::class, $databasePruner);

    try {
        pending_artisan('ai-harness:prune-herd', [
            '--path' => $fixture['project'],
            '--sites-path' => $fixture['sites'],
        ])
            ->expectsOutputToContain("custom_database_{$checksum}")
            ->expectsOutputToContain("custom_database_testing_{$checksum}")
            ->expectsConfirmation("Did this orphan belong to the selected project [{$fixture['project']}]?", 'yes')
            ->expectsChoice('What should be done with this orphan?', 'Remove Herd site and derived databases', [
                'Remove Herd site and derived databases',
                'Remove Herd site only',
                'Keep this site',
                'Stop reviewing sites',
            ])
            ->expectsConfirmation('Apply this permanent cleanup?', 'yes')
            ->assertSuccessful();

        expect($databasePruner->dropped)->toBe([
            "custom_database_{$checksum}",
            "custom_database_testing_{$checksum}",
        ]);
    } finally {
        putenv('PATH='.$fixture['original_path']);
        putenv('HERD_PRUNE_LOG');
    }
});

test('herd pruner does not offer selected project databases for an unassociated orphan', function (): void {
    $fixture = herd_prune_fixture();
    $databasePruner = new class extends HerdDatabasePruner
    {
        public function supported(): bool
        {
            return true;
        }

        public function drop(array $databases): void
        {
            throw new RuntimeException('Database cleanup must not run.');
        }
    };

    app()->instance(HerdDatabasePruner::class, $databasePruner);

    try {
        pending_artisan('ai-harness:prune-herd', [
            '--path' => $fixture['project'],
            '--sites-path' => $fixture['sites'],
        ])
            ->expectsConfirmation("Did this orphan belong to the selected project [{$fixture['project']}]?", 'no')
            ->expectsChoice('What should be done with this orphan?', 'Keep this site', [
                'Remove Herd site only',
                'Keep this site',
                'Stop reviewing sites',
            ])
            ->assertSuccessful();

        expect(trim((string) file_get_contents($fixture['herd_log'])))->toBe('');
    } finally {
        putenv('PATH='.$fixture['original_path']);
        putenv('HERD_PRUNE_LOG');
    }
});

test('herd pruner ignores missing links that were not named by the harness', function (): void {
    $fixture = herd_prune_fixture('manually-linked-site');

    try {
        pending_artisan('ai-harness:prune-herd', [
            '--path' => $fixture['project'],
            '--sites-path' => $fixture['sites'],
        ])
            ->expectsOutputToContain('No orphaned AI Harness Herd sites were found.')
            ->assertSuccessful();

        expect(trim((string) file_get_contents($fixture['herd_log'])))->toBe('');
    } finally {
        putenv('PATH='.$fixture['original_path']);
        putenv('HERD_PRUNE_LOG');
    }
});

test('herd pruner reports verified orphans without changing them in non-interactive runs', function (): void {
    $fixture = herd_prune_fixture();

    try {
        pending_artisan('ai-harness:prune-herd', [
            '--path' => $fixture['project'],
            '--sites-path' => $fixture['sites'],
            '--no-interaction' => true,
        ])
            ->expectsOutputToContain($fixture['site'])
            ->expectsOutputToContain('No changes were made because the command is not running interactively.')
            ->assertSuccessful();

        expect(trim((string) file_get_contents($fixture['herd_log'])))->toBe('');
    } finally {
        putenv('PATH='.$fixture['original_path']);
        putenv('HERD_PRUNE_LOG');
    }
});

test('herd pruner naming stays in parity with the generated provisioner', function (): void {
    $root = temp_directory('ai-harness-prune-parity');
    $projectName = str_repeat('Long_Project-Name_', 7);
    $project = $root.'/source/'.$projectName;
    $missingPath = $root.'/worktrees/5bab/'.$projectName;
    $sites = $root.'/herd-sites';

    mkdir($project, 0755, true);
    mkdir($sites, 0755, true);
    config(['ai-harness.project.database_name' => str_repeat('Custom_Database-', 7)]);

    pending_artisan('ai-harness:update', [
        '--path' => $project,
        '--with' => ['herd'],
    ])->assertSuccessful();

    $script = (string) file_get_contents($project.'/.codex/scripts/local-environment.sh');
    $caseOffset = strpos($script, "\ncase \"\${action}\" in\n");

    if ($caseOffset === false) {
        throw new RuntimeException('Unable to isolate the generated naming functions.');
    }

    $probeScript = $project.'/.codex/scripts/naming-probe.sh';
    file_put_contents($probeScript, substr($script, 0, $caseOffset));

    $probe = new Process([
        'bash',
        '-c',
        <<<'BASH'
set -euo pipefail
source "$SCRIPT"
herd_site_name
worktree_database_name
worktree_testing_database_name
BASH,
    ], $project, [
        'CODEX_WORKTREE_PATH' => $missingPath,
        'SCRIPT' => $probeScript,
    ]);
    $probe->mustRun();

    [$site, $database, $testingDatabase] = array_values(array_filter(explode("\n", trim($probe->getOutput()))));
    symlink($missingPath, $sites.'/'.$site);
    app()->instance(HerdDatabasePruner::class, supported_prune_database_pruner());

    pending_artisan('ai-harness:prune-herd', [
        '--path' => $project,
        '--sites-path' => $sites,
    ])
        ->expectsOutputToContain($site)
        ->expectsConfirmation("Did this orphan belong to the selected project [{$project}]?", 'yes')
        ->expectsOutputToContain($database)
        ->expectsOutputToContain($testingDatabase)
        ->expectsChoice('What should be done with this orphan?', 'Keep this site', [
            'Remove Herd site and derived databases',
            'Remove Herd site only',
            'Keep this site',
            'Stop reviewing sites',
        ])
        ->assertSuccessful();
});

test('herd pruner uses the configured database base for arbitrary worktree directory names', function (): void {
    $root = temp_directory('ai-harness-prune-arbitrary-worktree');
    $project = $root.'/source-project';
    $missingPath = $root.'/external-worktrees/feature-a';
    $sites = $root.'/herd-sites';
    $site = prune_expected_herd_site_name($missingPath);
    $checksum = prune_path_checksum($missingPath);

    mkdir($project, 0755, true);
    mkdir($sites, 0755, true);
    symlink($missingPath, $sites.'/'.$site);
    config(['ai-harness.project.database_name' => 'custom_database']);
    app()->instance(HerdDatabasePruner::class, supported_prune_database_pruner());

    pending_artisan('ai-harness:prune-herd', [
        '--path' => $project,
        '--sites-path' => $sites,
    ])
        ->expectsConfirmation("Did this orphan belong to the selected project [{$project}]?", 'yes')
        ->expectsOutputToContain("custom_database_{$checksum}")
        ->expectsOutputToContain("custom_database_testing_{$checksum}")
        ->expectsChoice('What should be done with this orphan?', 'Keep this site', [
            'Remove Herd site and derived databases',
            'Remove Herd site only',
            'Keep this site',
            'Stop reviewing sites',
        ])
        ->assertSuccessful();
});

test('herd pruner falls back to the worktree basename when the configured database base sanitizes empty', function (): void {
    $root = temp_directory('ai-harness-prune-empty-base');
    $project = $root.'/source-project';
    $missingPath = $root.'/external-worktrees/feature-a';
    $sites = $root.'/herd-sites';
    $site = prune_expected_herd_site_name($missingPath);
    $checksum = prune_path_checksum($missingPath);

    mkdir($project, 0755, true);
    mkdir($sites, 0755, true);
    symlink($missingPath, $sites.'/'.$site);
    config(['ai-harness.project.database_name' => '!!!']);
    app()->instance(HerdDatabasePruner::class, supported_prune_database_pruner());

    pending_artisan('ai-harness:prune-herd', [
        '--path' => $project,
        '--sites-path' => $sites,
    ])
        ->expectsConfirmation("Did this orphan belong to the selected project [{$project}]?", 'yes')
        ->expectsOutputToContain("feature_a_{$checksum}")
        ->expectsOutputToContain("feature_a_testing_{$checksum}")
        ->expectsChoice('What should be done with this orphan?', 'Keep this site', [
            'Remove Herd site and derived databases',
            'Remove Herd site only',
            'Keep this site',
            'Stop reviewing sites',
        ])
        ->assertSuccessful();
});

test('herd pruner reports an unlink failure without claiming the site was removed', function (): void {
    $fixture = herd_prune_fixture(herdScript: <<<'BASH'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "$HERD_PRUNE_LOG"

if [[ "${1:-}" == "unlink" ]]; then
    printf 'Herd service is unavailable.\n' >&2
    exit 37
fi
BASH);

    try {
        pending_artisan('ai-harness:prune-herd', [
            '--path' => $fixture['project'],
            '--sites-path' => $fixture['sites'],
        ])
            ->expectsChoice('What should be done with this orphan?', 'Remove Herd site only', [
                'Remove Herd site only',
                'Keep this site',
                'Stop reviewing sites',
            ])
            ->expectsConfirmation('Apply this permanent cleanup?', 'yes')
            ->expectsOutputToContain('Herd service is unavailable.')
            ->expectsOutputToContain("Unable to remove Herd site [{$fixture['site']}].")
            ->assertFailed();
    } finally {
        putenv('PATH='.$fixture['original_path']);
        putenv('HERD_PRUNE_LOG');
    }
});

/**
 * @return array{project: string, sites: string, missing_path: string, site: string, herd_log: string, original_path: string}
 */
function herd_prune_fixture(?string $site = null, ?string $herdScript = null): array
{
    $root = temp_directory('ai-harness-prune-herd');
    $project = $root.'/source-project';
    $sites = $root.'/herd-sites';
    $fakeBin = $root.'/fake-bin';
    $missingPath = $root.'/worktrees/5bab/source-project';
    $herdLog = temp_file('herd-prune-log');
    $originalPath = (string) getenv('PATH');

    mkdir($project, 0755, true);
    mkdir($sites, 0755, true);
    mkdir($fakeBin, 0755, true);
    config(['ai-harness.project.database_name' => 'custom_database']);

    $site ??= prune_expected_herd_site_name($missingPath);
    symlink($missingPath, $sites.'/'.$site);

    $herdScript ??= <<<'BASH'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "$HERD_PRUNE_LOG"
BASH;
    file_put_contents($fakeBin.'/herd', $herdScript);
    chmod($fakeBin.'/herd', 0755);
    putenv('PATH='.$fakeBin.PATH_SEPARATOR.$originalPath);
    putenv('HERD_PRUNE_LOG='.$herdLog);

    return [
        'project' => $project,
        'sites' => $sites,
        'missing_path' => $missingPath,
        'site' => $site,
        'herd_log' => $herdLog,
        'original_path' => $originalPath,
    ];
}

function prune_expected_herd_site_name(string $path): string
{
    $checksum = prune_path_checksum($path);
    $base = strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', basename($path).'-'.basename(dirname($path))));
    $base = trim($base, '-');
    $base = rtrim(substr($base, 0, 63 - strlen($checksum) - 1), '-');

    return ($base !== '' ? $base : 'codex-worktree').'-'.$checksum;
}

function supported_prune_database_pruner(): HerdDatabasePruner
{
    return new class extends HerdDatabasePruner
    {
        public function supported(): bool
        {
            return true;
        }

        public function drop(array $databases): void {}
    };
}

function prune_path_checksum(string $path): string
{
    $process = new Process(['cksum']);
    $process->setInput($path);
    $process->mustRun();

    if (preg_match('/^(\d+)\s+/', $process->getOutput(), $matches) !== 1) {
        throw new RuntimeException('Unable to calculate fixture checksum.');
    }

    return $matches[1];
}
