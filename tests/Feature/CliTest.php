<?php

declare(strict_types=1);

test('version output uses Composer package metadata instead of a future hard-coded version', function (): void {
    $root = temp_directory('harness-cli-version');
    $version = harness_process(['--version'], $root);
    $version->mustRun();

    expect($version->getOutput())->toContain('Laravel AI Harness')
        ->and(str_contains($version->getOutput(), '1.0.0-dev'))->toBeFalse();
});

test('init creates a healthy minimal project integration', function (): void {
    $root = temp_directory('harness-cli-init');
    file_put_contents($root.'/artisan', "<?php\n");
    file_put_contents($root.'/composer.json', json_encode([
        'name' => 'example/application',
        'scripts' => ['test' => 'pest'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");

    $init = harness_process(['init', '--path', $root], $root);
    $init->mustRun();

    expect($init->getOutput())->toContain('AI Harness initialized.')
        ->and(is_executable($root.'/.ai-harness'))->toBeTrue()
        ->and($root.'/.ai-harness.config')->toBeFile()
        ->and($root.'/AGENTS.md')->toBeFile()
        ->and($root.'/CLAUDE.md')->toBeFile()
        ->and((string) file_get_contents($root.'/composer.json'))->toContain('laravel-ai-harness:update');

    $doctor = harness_process(['doctor', '--path', $root], $root);
    $doctor->mustRun();

    expect($doctor->getOutput())->toContain('Runtime: native')
        ->toContain('OK Automatic Composer refresh hooks are installed')
        ->toContain('OK Codex instructions are installed')
        ->toContain('OK Claude hooks are installed');
});

test('update refreshes integration files without preparing the environment', function (): void {
    $root = temp_directory('harness-cli-update');
    file_put_contents($root.'/artisan', "<?php\n");
    file_put_contents($root.'/composer.json', json_encode([
        'name' => 'example/application',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");

    harness_process(['init', '--path', $root], $root)->mustRun();
    file_put_contents($root.'/AGENTS.md', "Project guidance.\n");

    $update = harness_process(['update', '--path', $root], $root);
    $update->mustRun();

    expect($update->getOutput())->toContain('AI Harness project files refreshed.')
        ->and((string) file_get_contents($root.'/AGENTS.md'))->toContain('Project guidance.')
        ->and((string) file_get_contents($root.'/AGENTS.md'))->toContain('<!-- ai-harness:start -->')
        ->and($root.'/.env')->not->toBeFile()
        ->and($root.'/.env.testing')->not->toBeFile()
        ->and($root.'/.ai-harness.state.json')->not->toBeFile();
});

test('runtime commands preserve options spaces and shell metacharacters as argv values', function (): void {
    $root = temp_directory('harness-cli-runtime');
    $log = temp_file('harness-runtime-log');
    $unexpected = $root.'/should-not-exist';
    file_put_contents($root.'/.ai-harness.config', "runtime=native\nservices=none\nagents=\n");
    file_put_contents($root.'/artisan', <<<'PHP'
<?php
file_put_contents((string) getenv('RUNTIME_LOG'), json_encode(array_slice($argv, 1), JSON_THROW_ON_ERROR));
exit(23);
PHP);

    $process = harness_process([
        'artisan',
        'migrate',
        '--env=testing',
        'value with spaces',
        '$(touch '.$unexpected.')',
    ], $root, [
        'RUNTIME_LOG' => $log,
    ]);
    $process->run();

    expect($process->getExitCode())->toBe(23)
        ->and(json_decode((string) file_get_contents($log), true, flags: JSON_THROW_ON_ERROR))->toBe([
            'migrate',
            '--env=testing',
            'value with spaces',
            '$(touch '.$unexpected.')',
        ])
        ->and($unexpected)->not->toBeFile();
});

test('test command maps to artisan test and forwards help instead of consuming it', function (): void {
    $root = temp_directory('harness-cli-test');
    $log = temp_file('harness-test-log');
    file_put_contents($root.'/.ai-harness.config', "runtime=native\nservices=none\nagents=\n");
    file_put_contents($root.'/artisan', <<<'PHP'
<?php
file_put_contents((string) getenv('RUNTIME_LOG'), json_encode(array_slice($argv, 1), JSON_THROW_ON_ERROR));
PHP);

    harness_process(['test', '--help'], $root, ['RUNTIME_LOG' => $log])->mustRun();

    expect(json_decode((string) file_get_contents($log), true, flags: JSON_THROW_ON_ERROR))
        ->toBe(['test', '--help']);
});

test('runtime commands forward standard input to the configured tool', function (): void {
    $root = temp_directory('harness-cli-stdin');
    $log = temp_file('harness-stdin-log');
    file_put_contents($root.'/.ai-harness.config', "runtime=native\nservices=none\nagents=\n");
    file_put_contents($root.'/read-stdin.php', <<<'PHP'
<?php
file_put_contents((string) getenv('RUNTIME_LOG'), stream_get_contents(STDIN));
PHP);

    $process = harness_process(['php', 'read-stdin.php'], $root, ['RUNTIME_LOG' => $log]);
    $process->setInput("from the calling terminal\n");
    $process->mustRun();

    expect(file_get_contents($log))->toBe("from the calling terminal\n");
});
