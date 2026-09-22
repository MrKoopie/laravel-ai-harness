<?php

declare(strict_types=1);

use MrKoopie\LaravelAiHarness\Application;
use MrKoopie\LaravelAiHarness\Environment\HerdSites;
use MrKoopie\LaravelAiHarness\Environment\SiteName;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;

/** @return array{string, string, string, string} */
function orphan_fixture(): array
{
    $root = temp_directory('harness-prune');
    $sites = $root.'/Library/Application Support/Herd/config/valet/Sites';
    mkdir($sites, 0755, true);
    $missing = $root.'/missing-worktree';
    $site = SiteName::forPath($missing);
    symlink($missing, $sites.'/'.$site);

    return [$root, $sites, $missing, $site];
}

test('orphan pruning reports verified missing targets and leaves live or unrelated sites alone', function (): void {
    [$root, $sites, $missing, $site] = orphan_fixture();
    symlink($missing, $sites.'/unrelated');
    symlink($root, $sites.'/'.SiteName::forPath($root));
    $process = harness_process(['prune-herd', '--sites-path='.$sites, '--no-interaction'], $root);
    $process->run();

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toContain($site, 'No changes')
        ->and($process->getOutput())->not->toContain('unrelated', SiteName::forPath($root))
        ->and(is_link($sites.'/'.$site))->toBeTrue();
});

test('orphan pruning confirms each removal and stops before unlink when unsecure fails', function (): void {
    [$root, $sites, , $site] = orphan_fixture();
    $log = $root.'/commands';
    write_executable($root.'/bin/herd', "#!/usr/bin/env bash\nprintf '%s\\n' \"\$*\" >> ".escapeshellarg($log)."\n[[ \"\$1\" != unsecure ]]\n");
    $oldHome = getenv('HOME');
    putenv('HOME='.$root);
    $oldPath = getenv('PATH');
    putenv('PATH='.$root.'/bin:'.$oldPath);

    try {
        $tester = new CommandTester((new Application)->find('prune-herd'));
        $tester->setInputs(['yes']);
        $status = $tester->execute(['--path' => $root, '--sites-path' => $sites], ['interactive' => true]);

        expect($status)->toBe(1)
            ->and(file_get_contents($log))->toBe('unsecure '.$site."\n")
            ->and(is_link($sites.'/'.$site))->toBeTrue();
    } finally {
        putenv('PATH='.$oldPath);
        putenv('HOME='.$oldHome);
    }
});

test('orphan pruning defaults to keeping a site', function (): void {
    [$root, $sites, , $site] = orphan_fixture();
    $tester = new CommandTester((new Application)->find('prune-herd'));
    $tester->setInputs(['']);
    $status = $tester->execute(['--path' => $root, '--sites-path' => $sites], ['interactive' => true]);

    expect($status)->toBe(0)
        ->and(is_link($sites.'/'.$site))->toBeTrue();
});

test('pruning recognizes legacy checksums and normalized relative targets', function (): void {
    [$root, $sites, $missing, $site] = orphan_fixture();
    unlink($sites.'/'.$site);
    symlink('../../../../../../missing-worktree', $sites.'/'.$site);
    $process = new Process(['cksum']);
    $legacyTarget = $root.'/old-worktree';
    $process->setInput($legacyTarget);
    $process->mustRun();
    $checksum = explode(' ', $process->getOutput())[0];
    $base = strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', basename($legacyTarget).'-'.basename($root)));
    $legacySite = rtrim(substr(trim($base, '-'), 0, 63 - strlen($checksum) - 1), '-').'-'.$checksum;
    symlink($legacyTarget, $sites.'/'.$legacySite);
    $orphans = (new HerdSites)->orphans($sites);

    expect($orphans)->toHaveCount(2)
        ->and($orphans[$site])->toBe($missing)
        ->and($orphans[$legacySite])->toBe($legacyTarget);
});

test('confirmed pruning unsecures then unlinks only the selected orphan', function (): void {
    [$root, $sites, , $site] = orphan_fixture();
    $log = $root.'/commands';
    write_executable($root.'/bin/herd', "#!/usr/bin/env bash\nprintf '%s\\n' \"\$*\" >> ".escapeshellarg($log)."\nif [[ \"\$1\" == unlink ]]; then\n  rm -- ".escapeshellarg($sites)."/\"\$2\"\nfi\n");
    $oldHome = getenv('HOME');
    $oldPath = getenv('PATH');
    putenv('HOME='.$root);
    putenv('PATH='.$root.'/bin:'.$oldPath);

    try {
        $tester = new CommandTester((new Application)->find('prune-herd'));
        $tester->setInputs(['yes']);
        $status = $tester->execute(['--path' => $root], ['interactive' => true]);
        clearstatcache(true, $sites.'/'.$site);

        expect($status)->toBe(0)
            ->and(file_get_contents($log))->toBe("unsecure $site\nunlink $site\n")
            ->and(is_link($sites.'/'.$site))->toBeFalse();
    } finally {
        putenv('HOME='.$oldHome);
        putenv('PATH='.$oldPath);
    }
});

test('pruning refuses a target that changes during unsecure', function (): void {
    [$root, $sites, , $site] = orphan_fixture();
    $log = $root.'/commands';
    write_executable($root.'/bin/herd', "#!/usr/bin/env bash\nprintf '%s\\n' \"\$*\" >> ".escapeshellarg($log)."\nln -sfn ".escapeshellarg($root).' '.escapeshellarg($sites)."/\"\$2\"\n");
    $oldHome = getenv('HOME');
    $oldPath = getenv('PATH');
    putenv('HOME='.$root);
    putenv('PATH='.$root.'/bin:'.$oldPath);

    try {
        $tester = new CommandTester((new Application)->find('prune-herd'));
        $tester->setInputs(['yes']);
        expect($tester->execute(['--path' => $root], ['interactive' => true]))->toBe(1)
            ->and(file_get_contents($log))->toBe("unsecure $site\n")
            ->and(readlink($sites.'/'.$site))->toBe($root);
    } finally {
        putenv('HOME='.$oldHome);
        putenv('PATH='.$oldPath);
    }
});

test('interactive inspection of an alternate directory cannot invoke Herd', function (): void {
    [$root, $sites, , $site] = orphan_fixture();
    $tester = new CommandTester((new Application)->find('prune-herd'));
    $tester->setInputs(['yes']);

    expect($tester->execute(['--path' => $root, '--sites-path' => $sites], ['interactive' => true]))->toBe(0)
        ->and($tester->getDisplay())->toContain('No changes')
        ->and(is_link($sites.'/'.$site))->toBeTrue();
});

test('pruning retains a failed unlink for another attempt', function (): void {
    [$root, $sites, , $site] = orphan_fixture();
    $log = $root.'/commands';
    write_executable($root.'/bin/herd', "#!/usr/bin/env bash\nprintf '%s\\n' \"\$*\" >> ".escapeshellarg($log)."\n[[ \"\$1\" != unlink ]]\n");
    $oldHome = getenv('HOME');
    $oldPath = getenv('PATH');
    putenv('HOME='.$root);
    putenv('PATH='.$root.'/bin:'.$oldPath);

    try {
        $tester = new CommandTester((new Application)->find('prune-herd'));
        $tester->setInputs(['yes']);
        expect($tester->execute(['--path' => $root], ['interactive' => true]))->toBe(1)
            ->and(file_get_contents($log))->toBe("unsecure $site\nunlink $site\n")
            ->and(is_link($sites.'/'.$site))->toBeTrue();
    } finally {
        putenv('HOME='.$oldHome);
        putenv('PATH='.$oldPath);
    }
});
