<?php

use Symfony\Component\Process\Process;

test('runtime helper bypasses herd php in managed agent worktrees', function (): void {
    $path = temp_directory('ai-harness-agent-php');

    pending_artisan('ai-harness:update', [
        '--path' => $path,
    ])->assertSuccessful();

    $runtimeLog = temp_file('runtime-log');
    $fakeBin = $path.'/fake-bin';

    mkdir($fakeBin, 0755, true);
    file_put_contents($fakeBin.'/php', <<<'BASH'
#!/usr/bin/env bash
printf 'php %s\n' "$*" >> "$RUNTIME_LOG"
BASH);
    chmod($fakeBin.'/php', 0755);
    file_put_contents($fakeBin.'/herd', <<<'BASH'
#!/usr/bin/env bash
printf 'herd %s\n' "$*" >> "$RUNTIME_LOG"
BASH);
    chmod($fakeBin.'/herd', 0755);

    $process = new Process(
        [$path.'/.dev/bin/ai-harness', 'migrate'],
        $path,
        [
            'PATH' => $fakeBin.PATH_SEPARATOR.getenv('PATH'),
            'RUNTIME_LOG' => $runtimeLog,
            'WORKTREE_PROFILE' => 'codex',
        ],
    );

    $process->mustRun();

    expect(trim((string) file_get_contents($runtimeLog)))
        ->toBe('php artisan migrate');
});

test('runtime helper executes the php version selected by herd without using the herd wrapper', function (): void {
    $path = temp_directory('ai-harness-selected-herd-php');

    pending_artisan('ai-harness:update', ['--path' => $path])->assertSuccessful();

    $runtimeLog = temp_file('runtime-log');
    $fakeBin = $path.'/herd-bin';
    mkdir($fakeBin, 0755, true);
    file_put_contents($fakeBin.'/herd.phar', 'fixture');
    file_put_contents($fakeBin.'/herd', <<<'BASH'
#!/usr/bin/env bash
printf 'herd-wrapper %s\n' "$*" >> "$RUNTIME_LOG"
BASH);
    file_put_contents($fakeBin.'/php', <<<'BASH'
#!/usr/bin/env bash
printf '%s\n' "$HERD_SELECTED_PHP"
BASH);
    file_put_contents($fakeBin.'/php84', <<<'BASH'
#!/usr/bin/env bash
printf 'php84 %s\n' "$*" >> "$RUNTIME_LOG"
BASH);
    chmod($fakeBin.'/herd', 0755);
    chmod($fakeBin.'/php', 0755);
    chmod($fakeBin.'/php84', 0755);

    (new Process([$path.'/.dev/bin/ai-harness', 'migrate'], $path, [
        'PATH' => $fakeBin.PATH_SEPARATOR.getenv('PATH'),
        'RUNTIME_LOG' => $runtimeLog,
        'HERD_SELECTED_PHP' => $fakeBin.'/php84',
        'WORKTREE_PROFILE' => 'codex',
    ]))->mustRun();

    expect(trim((string) file_get_contents($runtimeLog)))
        ->toBe('php84 artisan migrate');
});

test('runtime helper prefers sail only when the sail app service is running', function (): void {
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

if [[ "${1:-}" == "compose" && "${2:-}" == "ps" ]]; then
    printf 'mysql\nlaravel.test\n'
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

test('runtime helper runs pest directly with the generated phpunit config', function (): void {
    $path = temp_directory('ai-harness-test-config');

    pending_artisan('ai-harness:update', [
        '--path' => $path,
    ])->assertSuccessful();

    file_put_contents($path.'/.ai-harness.phpunit.xml', '<phpunit/>');

    $runtimeLog = temp_file('runtime-log');
    $fakeBin = $path.'/fake-bin';

    mkdir($path.'/vendor/bin', 0755, true);
    mkdir($fakeBin, 0755, true);

    file_put_contents($path.'/vendor/bin/pest', "#!/usr/bin/env bash\n");
    chmod($path.'/vendor/bin/pest', 0755);

    file_put_contents($fakeBin.'/herd', <<<'BASH'
#!/usr/bin/env bash
printf 'herd %s\n' "$*" >> "$RUNTIME_LOG"
BASH);
    chmod($fakeBin.'/herd', 0755);

    $process = new Process(
        [$path.'/.dev/bin/ai-harness', 'test', '--filter=ExampleTest'],
        $path,
        [
            'PATH' => $fakeBin.PATH_SEPARATOR.getenv('PATH'),
            'RUNTIME_LOG' => $runtimeLog,
        ],
    );

    $process->mustRun();

    expect(trim((string) file_get_contents($runtimeLog)))
        ->toBe('herd php vendor/bin/pest --configuration=.ai-harness.phpunit.xml --filter=ExampleTest');
});

test('runtime helper falls back to phpunit when pest is unavailable', function (): void {
    $path = temp_directory('ai-harness-test-config-phpunit');

    pending_artisan('ai-harness:update', [
        '--path' => $path,
    ])->assertSuccessful();

    file_put_contents($path.'/.ai-harness.phpunit.xml', '<phpunit/>');

    $runtimeLog = temp_file('runtime-log');
    $fakeBin = $path.'/fake-bin';

    mkdir($fakeBin, 0755, true);
    file_put_contents($fakeBin.'/herd', <<<'BASH'
#!/usr/bin/env bash
printf 'herd %s\n' "$*" >> "$RUNTIME_LOG"
BASH);
    chmod($fakeBin.'/herd', 0755);

    $process = new Process(
        [$path.'/.dev/bin/ai-harness', 'test', '--filter=ExampleTest'],
        $path,
        [
            'PATH' => $fakeBin.PATH_SEPARATOR.getenv('PATH'),
            'RUNTIME_LOG' => $runtimeLog,
        ],
    );

    $process->mustRun();

    expect(trim((string) file_get_contents($runtimeLog)))
        ->toBe('herd php vendor/bin/phpunit --configuration=.ai-harness.phpunit.xml --filter=ExampleTest');
});

test('runtime helper runs the test binary directly for a caller supplied phpunit configuration', function (): void {
    $path = temp_directory('ai-harness-test-custom-config');

    pending_artisan('ai-harness:update', [
        '--path' => $path,
    ])->assertSuccessful();

    $runtimeLog = temp_file('runtime-log');
    $fakeBin = $path.'/fake-bin';

    mkdir($path.'/vendor/bin', 0755, true);
    mkdir($fakeBin, 0755, true);

    file_put_contents($path.'/vendor/bin/pest', "#!/usr/bin/env bash\n");
    chmod($path.'/vendor/bin/pest', 0755);

    file_put_contents($fakeBin.'/herd', <<<'BASH'
#!/usr/bin/env bash
printf 'herd %s\n' "$*" >> "$RUNTIME_LOG"
BASH);
    chmod($fakeBin.'/herd', 0755);

    $process = new Process(
        [$path.'/.dev/bin/ai-harness', 'test', '--configuration=custom.xml'],
        $path,
        [
            'PATH' => $fakeBin.PATH_SEPARATOR.getenv('PATH'),
            'RUNTIME_LOG' => $runtimeLog,
        ],
    );

    $process->mustRun();

    expect(trim((string) file_get_contents($runtimeLog)))
        ->toBe('herd php vendor/bin/pest --configuration=custom.xml');
});

test('runtime helper routes plain test through artisan when no phpunit configuration applies', function (): void {
    $path = temp_directory('ai-harness-test-plain');

    pending_artisan('ai-harness:update', [
        '--path' => $path,
    ])->assertSuccessful();

    $runtimeLog = temp_file('runtime-log');
    $fakeBin = $path.'/fake-bin';

    mkdir($path.'/vendor/bin', 0755, true);
    mkdir($fakeBin, 0755, true);

    file_put_contents($path.'/vendor/bin/pest', "#!/usr/bin/env bash\n");
    chmod($path.'/vendor/bin/pest', 0755);

    file_put_contents($fakeBin.'/herd', <<<'BASH'
#!/usr/bin/env bash
printf 'herd %s\n' "$*" >> "$RUNTIME_LOG"
BASH);
    chmod($fakeBin.'/herd', 0755);

    $process = new Process(
        [$path.'/.dev/bin/ai-harness', 'test', '--filter=ExampleTest'],
        $path,
        [
            'PATH' => $fakeBin.PATH_SEPARATOR.getenv('PATH'),
            'RUNTIME_LOG' => $runtimeLog,
        ],
    );

    $process->mustRun();

    expect(trim((string) file_get_contents($runtimeLog)))
        ->toBe('herd php artisan test --filter=ExampleTest');
});

test('runtime helper runs pest directly through sail for the generated phpunit config', function (): void {
    $path = temp_directory('ai-harness-test-sail-pest');

    pending_artisan('ai-harness:update', [
        '--path' => $path,
    ])->assertSuccessful();

    file_put_contents($path.'/.ai-harness.phpunit.xml', '<phpunit/>');

    $runtimeLog = temp_file('runtime-log');
    $fakeBin = $path.'/fake-bin';

    mkdir($path.'/vendor/bin', 0755, true);
    mkdir($fakeBin, 0755, true);

    file_put_contents($path.'/vendor/bin/sail', <<<'BASH'
#!/usr/bin/env bash
printf 'sail %s\n' "$*" >> "$RUNTIME_LOG"
BASH);
    chmod($path.'/vendor/bin/sail', 0755);

    file_put_contents($path.'/vendor/bin/pest', "#!/usr/bin/env bash\n");
    chmod($path.'/vendor/bin/pest', 0755);

    file_put_contents($fakeBin.'/docker', <<<'BASH'
#!/usr/bin/env bash
if [[ "${1:-}" == "info" ]]; then
    exit 0
fi

if [[ "${1:-}" == "compose" && "${2:-}" == "ps" ]]; then
    printf 'mysql\nlaravel.test\n'
    exit 0
fi

exit 1
BASH);
    chmod($fakeBin.'/docker', 0755);

    $process = new Process(
        [$path.'/.dev/bin/ai-harness', 'test'],
        $path,
        [
            'PATH' => $fakeBin.PATH_SEPARATOR.getenv('PATH'),
            'RUNTIME_LOG' => $runtimeLog,
        ],
    );

    $process->mustRun();

    expect(trim((string) file_get_contents($runtimeLog)))
        ->toBe('sail php vendor/bin/pest --configuration=.ai-harness.phpunit.xml');
});

test('runtime helper runs pest in parallel by default when parallel support is available', function (): void {
    $path = temp_directory('ai-harness-test-parallel');

    pending_artisan('ai-harness:update', [
        '--path' => $path,
    ])->assertSuccessful();

    file_put_contents($path.'/.ai-harness.phpunit.xml', '<phpunit/>');

    $runtimeLog = temp_file('runtime-log');
    $fakeBin = $path.'/fake-bin';

    mkdir($path.'/vendor/bin', 0755, true);
    mkdir($fakeBin, 0755, true);

    file_put_contents($path.'/vendor/bin/pest', "#!/usr/bin/env bash\n");
    chmod($path.'/vendor/bin/pest', 0755);
    file_put_contents($path.'/vendor/bin/paratest', "#!/usr/bin/env bash\n");
    chmod($path.'/vendor/bin/paratest', 0755);
    file_put_contents($path.'/vendor/pest-plugins.json', '["Pest\\\\Plugins\\\\Parallel"]');

    file_put_contents($fakeBin.'/herd', <<<'BASH'
#!/usr/bin/env bash
printf 'herd %s\n' "$*" >> "$RUNTIME_LOG"
BASH);
    chmod($fakeBin.'/herd', 0755);

    $process = new Process(
        [$path.'/.dev/bin/ai-harness', 'test', '--filter=ExampleTest'],
        $path,
        [
            'PATH' => $fakeBin.PATH_SEPARATOR.getenv('PATH'),
            'RUNTIME_LOG' => $runtimeLog,
        ],
    );

    $process->mustRun();

    expect(trim((string) file_get_contents($runtimeLog)))
        ->toBe('herd php vendor/bin/pest --parallel --configuration=.ai-harness.phpunit.xml --filter=ExampleTest');
});

test('runtime helper adds parallel when only --processes is supplied', function (): void {
    $path = temp_directory('ai-harness-test-processes');

    pending_artisan('ai-harness:update', [
        '--path' => $path,
    ])->assertSuccessful();

    file_put_contents($path.'/.ai-harness.phpunit.xml', '<phpunit/>');

    $runtimeLog = temp_file('runtime-log');
    $fakeBin = $path.'/fake-bin';

    mkdir($path.'/vendor/bin', 0755, true);
    mkdir($fakeBin, 0755, true);

    file_put_contents($path.'/vendor/bin/pest', "#!/usr/bin/env bash\n");
    chmod($path.'/vendor/bin/pest', 0755);
    file_put_contents($path.'/vendor/bin/paratest', "#!/usr/bin/env bash\n");
    chmod($path.'/vendor/bin/paratest', 0755);
    file_put_contents($path.'/vendor/pest-plugins.json', '["Pest\\\\Plugins\\\\Parallel"]');

    file_put_contents($fakeBin.'/herd', <<<'BASH'
#!/usr/bin/env bash
printf 'herd %s\n' "$*" >> "$RUNTIME_LOG"
BASH);
    chmod($fakeBin.'/herd', 0755);

    $process = new Process(
        [$path.'/.dev/bin/ai-harness', 'test', '--processes=4'],
        $path,
        [
            'PATH' => $fakeBin.PATH_SEPARATOR.getenv('PATH'),
            'RUNTIME_LOG' => $runtimeLog,
        ],
    );

    $process->mustRun();

    expect(trim((string) file_get_contents($runtimeLog)))
        ->toBe('herd php vendor/bin/pest --parallel --configuration=.ai-harness.phpunit.xml --processes=4');
});

test('runtime helper does not double a caller supplied parallel flag', function (): void {
    $path = temp_directory('ai-harness-test-parallel-explicit');

    pending_artisan('ai-harness:update', [
        '--path' => $path,
    ])->assertSuccessful();

    file_put_contents($path.'/.ai-harness.phpunit.xml', '<phpunit/>');

    $runtimeLog = temp_file('runtime-log');
    $fakeBin = $path.'/fake-bin';

    mkdir($path.'/vendor/bin', 0755, true);
    mkdir($fakeBin, 0755, true);

    file_put_contents($path.'/vendor/bin/pest', "#!/usr/bin/env bash\n");
    chmod($path.'/vendor/bin/pest', 0755);
    file_put_contents($path.'/vendor/bin/paratest', "#!/usr/bin/env bash\n");
    chmod($path.'/vendor/bin/paratest', 0755);
    file_put_contents($path.'/vendor/pest-plugins.json', '["Pest\\\\Plugins\\\\Parallel"]');

    file_put_contents($fakeBin.'/herd', <<<'BASH'
#!/usr/bin/env bash
printf 'herd %s\n' "$*" >> "$RUNTIME_LOG"
BASH);
    chmod($fakeBin.'/herd', 0755);

    $process = new Process(
        [$path.'/.dev/bin/ai-harness', 'test', '--parallel'],
        $path,
        [
            'PATH' => $fakeBin.PATH_SEPARATOR.getenv('PATH'),
            'RUNTIME_LOG' => $runtimeLog,
        ],
    );

    $process->mustRun();

    expect(trim((string) file_get_contents($runtimeLog)))
        ->toBe('herd php vendor/bin/pest --configuration=.ai-harness.phpunit.xml --parallel');
});

test('runtime helper does not parallelise a coverage run', function (): void {
    $path = temp_directory('ai-harness-test-coverage');

    pending_artisan('ai-harness:update', [
        '--path' => $path,
    ])->assertSuccessful();

    file_put_contents($path.'/.ai-harness.phpunit.xml', '<phpunit/>');

    $runtimeLog = temp_file('runtime-log');
    $fakeBin = $path.'/fake-bin';

    mkdir($path.'/vendor/bin', 0755, true);
    mkdir($fakeBin, 0755, true);

    file_put_contents($path.'/vendor/bin/pest', "#!/usr/bin/env bash\n");
    chmod($path.'/vendor/bin/pest', 0755);
    file_put_contents($path.'/vendor/bin/paratest', "#!/usr/bin/env bash\n");
    chmod($path.'/vendor/bin/paratest', 0755);
    file_put_contents($path.'/vendor/pest-plugins.json', '["Pest\\\\Plugins\\\\Parallel"]');

    file_put_contents($fakeBin.'/herd', <<<'BASH'
#!/usr/bin/env bash
printf 'herd %s\n' "$*" >> "$RUNTIME_LOG"
BASH);
    chmod($fakeBin.'/herd', 0755);

    $process = new Process(
        [$path.'/.dev/bin/ai-harness', 'test', '--coverage'],
        $path,
        [
            'PATH' => $fakeBin.PATH_SEPARATOR.getenv('PATH'),
            'RUNTIME_LOG' => $runtimeLog,
        ],
    );

    $process->mustRun();

    expect(trim((string) file_get_contents($runtimeLog)))
        ->toBe('herd php vendor/bin/pest --configuration=.ai-harness.phpunit.xml --coverage');
});

test('runtime helper never parallelises the phpunit fallback', function (): void {
    $path = temp_directory('ai-harness-test-phpunit-no-parallel');

    pending_artisan('ai-harness:update', [
        '--path' => $path,
    ])->assertSuccessful();

    file_put_contents($path.'/.ai-harness.phpunit.xml', '<phpunit/>');

    $runtimeLog = temp_file('runtime-log');
    $fakeBin = $path.'/fake-bin';

    mkdir($path.'/vendor/bin', 0755, true);
    mkdir($fakeBin, 0755, true);

    file_put_contents($path.'/vendor/bin/paratest', "#!/usr/bin/env bash\n");
    chmod($path.'/vendor/bin/paratest', 0755);
    file_put_contents($path.'/vendor/pest-plugins.json', '["Pest\\\\Plugins\\\\Parallel"]');

    file_put_contents($fakeBin.'/herd', <<<'BASH'
#!/usr/bin/env bash
printf 'herd %s\n' "$*" >> "$RUNTIME_LOG"
BASH);
    chmod($fakeBin.'/herd', 0755);

    $process = new Process(
        [$path.'/.dev/bin/ai-harness', 'test', '--filter=ExampleTest'],
        $path,
        [
            'PATH' => $fakeBin.PATH_SEPARATOR.getenv('PATH'),
            'RUNTIME_LOG' => $runtimeLog,
        ],
    );

    $process->mustRun();

    expect(trim((string) file_get_contents($runtimeLog)))
        ->toBe('herd php vendor/bin/phpunit --configuration=.ai-harness.phpunit.xml --filter=ExampleTest');
});

test('runtime helper does not parallelise when the pest plugin registry is absent', function (): void {
    $path = temp_directory('ai-harness-test-no-registry');

    pending_artisan('ai-harness:update', [
        '--path' => $path,
    ])->assertSuccessful();

    file_put_contents($path.'/.ai-harness.phpunit.xml', '<phpunit/>');

    $runtimeLog = temp_file('runtime-log');
    $fakeBin = $path.'/fake-bin';

    mkdir($path.'/vendor/bin', 0755, true);
    mkdir($fakeBin, 0755, true);

    file_put_contents($path.'/vendor/bin/pest', "#!/usr/bin/env bash\n");
    chmod($path.'/vendor/bin/pest', 0755);
    file_put_contents($path.'/vendor/bin/paratest', "#!/usr/bin/env bash\n");
    chmod($path.'/vendor/bin/paratest', 0755);

    file_put_contents($fakeBin.'/herd', <<<'BASH'
#!/usr/bin/env bash
printf 'herd %s\n' "$*" >> "$RUNTIME_LOG"
BASH);
    chmod($fakeBin.'/herd', 0755);

    $process = new Process(
        [$path.'/.dev/bin/ai-harness', 'test', '--filter=ExampleTest'],
        $path,
        [
            'PATH' => $fakeBin.PATH_SEPARATOR.getenv('PATH'),
            'RUNTIME_LOG' => $runtimeLog,
        ],
    );

    $process->mustRun();

    expect(trim((string) file_get_contents($runtimeLog)))
        ->toBe('herd php vendor/bin/pest --configuration=.ai-harness.phpunit.xml --filter=ExampleTest');
});

test('runtime helper disables parallel when AI_HARNESS_PARALLEL is set to zero', function (): void {
    $path = temp_directory('ai-harness-test-parallel-opt-out');

    pending_artisan('ai-harness:update', [
        '--path' => $path,
    ])->assertSuccessful();

    file_put_contents($path.'/.ai-harness.phpunit.xml', '<phpunit/>');

    $runtimeLog = temp_file('runtime-log');
    $fakeBin = $path.'/fake-bin';

    mkdir($path.'/vendor/bin', 0755, true);
    mkdir($fakeBin, 0755, true);

    file_put_contents($path.'/vendor/bin/pest', "#!/usr/bin/env bash\n");
    chmod($path.'/vendor/bin/pest', 0755);
    file_put_contents($path.'/vendor/bin/paratest', "#!/usr/bin/env bash\n");
    chmod($path.'/vendor/bin/paratest', 0755);
    file_put_contents($path.'/vendor/pest-plugins.json', '["Pest\\\\Plugins\\\\Parallel"]');

    file_put_contents($fakeBin.'/herd', <<<'BASH'
#!/usr/bin/env bash
printf 'herd %s\n' "$*" >> "$RUNTIME_LOG"
BASH);
    chmod($fakeBin.'/herd', 0755);

    $process = new Process(
        [$path.'/.dev/bin/ai-harness', 'test', '--filter=ExampleTest'],
        $path,
        [
            'AI_HARNESS_PARALLEL' => '0',
            'PATH' => $fakeBin.PATH_SEPARATOR.getenv('PATH'),
            'RUNTIME_LOG' => $runtimeLog,
        ],
    );

    $process->mustRun();

    expect(trim((string) file_get_contents($runtimeLog)))
        ->toBe('herd php vendor/bin/pest --configuration=.ai-harness.phpunit.xml --filter=ExampleTest');
});

test('runtime helper does not parallelise a retry run', function (): void {
    expect(run_parallel_capable_wrapper(['test', '--retry']))
        ->toBe('herd php vendor/bin/pest --configuration=.ai-harness.phpunit.xml --retry');
});

test('runtime helper does not parallelise a profile run', function (): void {
    expect(run_parallel_capable_wrapper(['test', '--profile']))
        ->toBe('herd php vendor/bin/pest --configuration=.ai-harness.phpunit.xml --profile');
});

test('runtime helper does not parallelise a list-tests run', function (): void {
    expect(run_parallel_capable_wrapper(['test', '--list-tests']))
        ->toBe('herd php vendor/bin/pest --configuration=.ai-harness.phpunit.xml --list-tests');
});

test('runtime helper parallelises a plain path run', function (): void {
    expect(run_parallel_capable_wrapper(['test', 'tests/Unit/ExampleTest.php']))
        ->toBe('herd php vendor/bin/pest --parallel --configuration=.ai-harness.phpunit.xml tests/Unit/ExampleTest.php');
});

test('runtime helper ignores sail when the app service is not running', function (): void {
    $path = temp_directory('ai-harness-herd-runtime');

    pending_artisan('ai-harness:update', [
        '--path' => $path,
    ])->assertSuccessful();

    $runtimeLog = temp_file('runtime-log');
    $fakeBin = $path.'/fake-bin';

    mkdir($path.'/vendor/bin', 0755, true);
    mkdir($fakeBin, 0755, true);

    file_put_contents($path.'/vendor/bin/sail', <<<'BASH'
#!/usr/bin/env bash
printf 'sail should not run\n' >> "$RUNTIME_LOG"
exit 44
BASH);
    chmod($path.'/vendor/bin/sail', 0755);

    file_put_contents($fakeBin.'/docker', <<<'BASH'
#!/usr/bin/env bash
if [[ "${1:-}" == "info" ]]; then
    exit 0
fi

if [[ "${1:-}" == "compose" && "${2:-}" == "ps" ]]; then
    printf 'mysql\n'
    exit 0
fi

exit 1
BASH);
    chmod($fakeBin.'/docker', 0755);

    file_put_contents($fakeBin.'/herd', <<<'BASH'
#!/usr/bin/env bash
printf 'herd %s\n' "$*" >> "$RUNTIME_LOG"
BASH);
    chmod($fakeBin.'/herd', 0755);

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
        ->toBe('herd php artisan migrate --env=testing');
});

test('runtime helper honors custom sail app service names', function (): void {
    $path = temp_directory('ai-harness-custom-sail');

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

if [[ "${1:-}" == "compose" && "${2:-}" == "ps" ]]; then
    printf 'mysql\napp\n'
    exit 0
fi

exit 1
BASH);
    chmod($fakeBin.'/docker', 0755);

    $process = new Process(
        [$path.'/.dev/bin/ai-harness', 'migrate', '--env=testing'],
        $path,
        [
            'APP_SERVICE' => 'app',
            'PATH' => $fakeBin.PATH_SEPARATOR.getenv('PATH'),
            'RUNTIME_LOG' => $runtimeLog,
        ],
    );

    $process->mustRun();

    expect(trim((string) file_get_contents($runtimeLog)))
        ->toBe('sail artisan migrate --env=testing');
});

test('runtime helper resolves herd from the well known macos path', function (): void {
    $path = temp_directory('ai-harness-herd-known-path');
    $home = temp_directory('ai-harness-home');
    $herdDirectory = $home.'/Library/Application Support/Herd/bin';

    pending_artisan('ai-harness:update', [
        '--path' => $path,
    ])->assertSuccessful();

    $runtimeLog = temp_file('runtime-log');

    mkdir($herdDirectory, 0755, true);
    file_put_contents($herdDirectory.'/herd', <<<'BASH'
#!/usr/bin/env bash
printf 'herd %s\n' "$*" >> "$RUNTIME_LOG"
BASH);
    chmod($herdDirectory.'/herd', 0755);

    $process = new Process(
        [$path.'/.dev/bin/ai-harness', 'migrate', '--env=testing'],
        $path,
        [
            'HOME' => $home,
            'PATH' => '/usr/bin:/bin:/usr/sbin:/sbin',
            'RUNTIME_LOG' => $runtimeLog,
        ],
    );

    $process->mustRun();

    expect(trim((string) file_get_contents($runtimeLog)))
        ->toBe('herd php artisan migrate --env=testing');
});

test('runtime helper skips doctor successfully while vendor autoload is missing', function (): void {
    $path = temp_directory('ai-harness-doctor-missing-vendor');

    pending_artisan('ai-harness:update', [
        '--path' => $path,
    ])->assertSuccessful();

    $process = new Process(
        [$path.'/.dev/bin/ai-harness', 'ai-harness:doctor'],
        $path,
        [
            'PATH' => '/usr/bin:/bin:/usr/sbin:/sbin',
        ],
    );

    $process->mustRun();

    expect($process->getErrorOutput())
        ->toContain('ai-harness:doctor skipped: provisioning in progress; vendor/autoload.php is missing.');
});
