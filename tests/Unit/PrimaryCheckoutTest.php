<?php

declare(strict_types=1);

use MrKoopie\LaravelAiHarness\Support\PrimaryCheckout;
use Symfony\Component\Process\Process;

foreach (['independent repository', 'dangling repository pointer', 'external application symlink'] as $boundary) {
    test('nested project inheritance stops at '.$boundary, function () use ($boundary): void {
        $primary = temp_directory('harness-primary-boundary');
        $worktree = temp_directory('harness-worktree-boundary');
        $outside = temp_directory('harness-outside-boundary');
        (new Process(['git', 'init', '--initial-branch=main', $primary]))->mustRun();
        (new Process(['git', '-c', 'user.name=Harness Test', '-c', 'user.email=harness@example.invalid', 'commit', '--allow-empty', '--no-gpg-sign', '-m', 'Fixture'], $primary))->mustRun();
        (new Process(['git', 'worktree', 'add', '--detach', $worktree], $primary))->mustRun();
        mkdir($primary.'/apps');
        mkdir($worktree.'/apps/laravel', 0755, true);
        $root = $worktree.'/apps/laravel';

        if ($boundary === 'external application symlink') {
            symlink($outside, $primary.'/apps/laravel');
        } else {
            mkdir($primary.'/apps/laravel');

            if ($boundary === 'independent repository') {
                (new Process(['git', 'init', $root]))->mustRun();
            } else {
                symlink($outside.'/missing', $root.'/.git');
            }
        }

        try {
            expect(PrimaryCheckout::forWorktree($root))->toBeNull();
        } finally {
            if (is_link($root.'/.git')) {
                unlink($root.'/.git');
            }

            // Git requires a second force flag to remove a fixture containing an independent repository.
            (new Process(['git', 'worktree', 'remove', '--force', '--force', $worktree], $primary))->mustRun();
        }
    });
}
