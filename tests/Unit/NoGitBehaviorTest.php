<?php

declare(strict_types=1);

test('runtime implementation contains no Git command execution', function (): void {
    $paths = [
        package_root().'/bin',
        package_root().'/src',
        package_root().'/resources',
    ];

    foreach ($paths as $path) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $contents = file_get_contents($file->getPathname());

            expect($contents)->not->toBeFalse()
                ->and((string) $contents)->not->toMatch('/\bgit\s+(fetch|pull|checkout|switch|branch|rebase|worktree)\b/i');
        }
    }
});
