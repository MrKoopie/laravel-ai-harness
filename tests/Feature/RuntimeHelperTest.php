<?php

use Symfony\Component\Process\Process;

test('runtime helper prefers sail when sail and a container runtime are available', function (): void {
    $path = temp_directory('ai-harness-sail');

    pending_artisan('ai-harness:update', [
        '--path' => $path,
    ])->assertSuccessful();

    $runtimeLog = temp_file('runtime-log');
    $fakeBin = $path.'/fake-bin';

    mkdir($path.'/vendor/bin', 0755, true);
    mkdir($fakeBin, 0755, true);

    file_put_contents($path.'/vendor/bin/sail', <<<'BASH'
#!/usr/bin/env bash
printf 'sail %s\n' "$*" >> "$RUNTIME_LOG"
BASH);
    chmod($path.'/vendor/bin/sail', 0755);

    file_put_contents($fakeBin.'/docker', <<<'BASH'
#!/usr/bin/env bash
if [[ "${1:-}" == "info" ]]; then
    exit 0
fi

exit 1
BASH);
    chmod($fakeBin.'/docker', 0755);

    $process = new Process(
        [$path.'/.dev/bin/ai-harness', 'migrate', '--env=testing'],
        $path,
        [
            'PATH' => $fakeBin.PATH_SEPARATOR.getenv('PATH'),
            'RUNTIME_LOG' => $runtimeLog,
        ],
    );

    $process->mustRun();

    expect(trim((string) file_get_contents($runtimeLog)))
        ->toBe('sail artisan migrate --env=testing');
});
