<?php

declare(strict_types=1);

test('init creates a healthy minimal project integration', function (): void {
    $root = temp_directory('harness-cli-init');
    file_put_contents($root.'/artisan', "<?php\n");

    $init = harness_process(['init', '--path', $root], $root);
    $init->mustRun();

    expect($init->getOutput())->toContain('AI Harness initialized.')
        ->and(is_executable($root.'/.ai-harness'))->toBeTrue()
        ->and($root.'/.ai-harness.config')->toBeFile()
        ->and($root.'/AGENTS.md')->toBeFile()
        ->and($root.'/CLAUDE.md')->toBeFile();

    $doctor = harness_process(['doctor', '--path', $root], $root);
    $doctor->mustRun();

    expect($doctor->getOutput())->toContain('Runtime: native')
        ->toContain('OK Codex instructions are installed')
        ->toContain('OK Claude hooks are installed');
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
