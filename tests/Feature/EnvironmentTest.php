<?php

declare(strict_types=1);
use MrKoopie\LaravelAiHarness\Environment\SiteName;

test('setup composes Herd with selected Sail services and cleanup only unlinks owned Herd state', function (): void {
    $root = temp_directory('harness-environment');
    $fakeBin = $root.'/fake-bin';
    $herdLog = temp_file('herd-log');
    $sailLog = temp_file('sail-log');
    mkdir($fakeBin, 0755, true);
    mkdir($root.'/vendor/bin', 0755, true);
    file_put_contents($root.'/vendor/autoload.php', "<?php\n");
    file_put_contents($root.'/artisan', "<?php\n");
    file_put_contents($root.'/.env.example', "APP_KEY=\n");
    file_put_contents($root.'/.ai-harness.config', implode("\n", [
        'runtime=herd',
        'services=sail',
        'agents=',
        'sail_services=mysql,redis',
        'herd_secure=true',
        'herd_php=8.4',
        '',
    ]));
    write_executable($fakeBin.'/herd', <<<'BASH'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "${HERD_LOG}"
BASH);
    write_executable($root.'/vendor/bin/sail', <<<'BASH'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "${SAIL_LOG}"
BASH);

    $environment = [
        'PATH' => $fakeBin.PATH_SEPARATOR.getenv('PATH'),
        'HERD_LOG' => $herdLog,
        'SAIL_LOG' => $sailLog,
    ];

    harness_process(['setup'], $root, $environment)->mustRun();

    $state = json_decode((string) file_get_contents($root.'/.ai-harness.state.json'), true, flags: JSON_THROW_ON_ERROR);
    $site = $state['herd_site'];

    expect($state)->toBe(['herd_site' => $site, 'herd_secured' => true])
        ->and(file($sailLog, FILE_IGNORE_NEW_LINES))->toBe(['up -d mysql redis'])
        ->and(file($herdLog, FILE_IGNORE_NEW_LINES))->toBe([
            'link '.$site.' --no-interaction',
            'secure '.$site,
            'isolate 8.4',
            'php '.$root.'/artisan key:generate --ansi',
        ])
        ->and($root.'/.env')->toBeFile();

    harness_process(['cleanup'], $root, $environment)->mustRun();

    expect(file($herdLog, FILE_IGNORE_NEW_LINES))->toBe([
        'link '.$site.' --no-interaction',
        'secure '.$site,
        'isolate 8.4',
        'php '.$root.'/artisan key:generate --ansi',
        'unsecure '.$site,
        'unlink '.$site,
    ])
        ->and($root.'/.ai-harness.state.json')->not->toBeFile()
        ->and(file($sailLog, FILE_IGNORE_NEW_LINES))->toBe(['up -d mysql redis']);

    harness_process(['down'], $root, $environment)->mustRun();

    expect(file($sailLog, FILE_IGNORE_NEW_LINES))->toBe([
        'up -d mysql redis',
        'stop mysql redis',
    ]);
});

test('Sail runtime starts laravel.test with or without explicitly managed supporting services', function (): void {
    foreach ([
        ['services=none', 'sail_services=', 'up -d laravel.test', 'stop laravel.test'],
        ['services=sail', 'sail_services=mysql,redis', 'up -d laravel.test mysql redis', 'stop laravel.test mysql redis'],
    ] as [$services, $sailServices, $expectedSetup, $expectedDown]) {
        $root = temp_directory('harness-sail-runtime');
        $sailLog = temp_file('sail-runtime-log');
        mkdir($root.'/vendor/bin', 0755, true);
        file_put_contents($root.'/vendor/autoload.php', "<?php\n");
        file_put_contents($root.'/.ai-harness.config', implode("\n", [
            'runtime=sail',
            $services,
            'agents=',
            $sailServices,
            '',
        ]));
        write_executable($root.'/vendor/bin/sail', <<<'BASH'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "${SAIL_LOG}"
BASH);

        $environment = ['SAIL_LOG' => $sailLog];

        harness_process(['setup'], $root, $environment)->mustRun();
        harness_process(['up'], $root, $environment)->mustRun();
        harness_process(['down'], $root, $environment)->mustRun();

        expect(file($sailLog, FILE_IGNORE_NEW_LINES))->toBe([
            $expectedSetup,
            $expectedSetup,
            $expectedDown,
        ]);
    }
});

test('cleanup preserves Herd ownership state when removing HTTPS fails', function (): void {
    $root = temp_directory('harness-unsecure-failure');
    $fakeBin = $root.'/fake-bin';
    $herdLog = temp_file('herd-unsecure-log');
    $site = SiteName::forPath($root);
    mkdir($fakeBin, 0755, true);
    file_put_contents($root.'/.ai-harness.config', "runtime=herd\nservices=none\nagents=\n");
    file_put_contents($root.'/.ai-harness.state.json', json_encode([
        'herd_site' => $site,
        'herd_secured' => true,
    ], JSON_THROW_ON_ERROR));
    write_executable($fakeBin.'/herd', <<<'BASH'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "${HERD_LOG}"
if [[ "$1" == "unsecure" ]]; then
    exit 17
fi
BASH);

    $process = harness_process(['cleanup'], $root, [
        'PATH' => $fakeBin.PATH_SEPARATOR.getenv('PATH'),
        'HERD_LOG' => $herdLog,
    ]);
    $process->run();

    expect($process->getExitCode())->toBe(17)
        ->and(file($herdLog, FILE_IGNORE_NEW_LINES))->toBe(['unsecure '.$site])
        ->and(json_decode((string) file_get_contents($root.'/.ai-harness.state.json'), true, flags: JSON_THROW_ON_ERROR))
        ->toBe(['herd_site' => $site, 'herd_secured' => true]);
});

test('cleanup records successful HTTPS removal before a failed Herd unlink', function (): void {
    $root = temp_directory('harness-unlink-failure');
    $fakeBin = $root.'/fake-bin';
    $herdLog = temp_file('herd-unlink-log');
    $site = SiteName::forPath($root);
    mkdir($fakeBin, 0755, true);
    file_put_contents($root.'/.ai-harness.config', "runtime=herd\nservices=none\nagents=\n");
    file_put_contents($root.'/.ai-harness.state.json', json_encode([
        'herd_site' => $site,
        'herd_secured' => true,
    ], JSON_THROW_ON_ERROR));
    write_executable($fakeBin.'/herd', <<<'BASH'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "${HERD_LOG}"
if [[ "$1" == "unlink" && "${FAIL_UNLINK:-}" == "1" ]]; then
    exit 19
fi
BASH);

    $first = harness_process(['cleanup'], $root, [
        'PATH' => $fakeBin.PATH_SEPARATOR.getenv('PATH'),
        'HERD_LOG' => $herdLog,
        'FAIL_UNLINK' => '1',
    ]);
    $first->run();

    expect($first->getExitCode())->toBe(19)
        ->and(json_decode((string) file_get_contents($root.'/.ai-harness.state.json'), true, flags: JSON_THROW_ON_ERROR))
        ->toBe(['herd_site' => $site]);

    harness_process(['cleanup'], $root, [
        'PATH' => $fakeBin.PATH_SEPARATOR.getenv('PATH'),
        'HERD_LOG' => $herdLog,
        'FAIL_UNLINK' => '0',
    ])->mustRun();

    expect(file($herdLog, FILE_IGNORE_NEW_LINES))->toBe([
        'unsecure '.$site,
        'unlink '.$site,
        'unlink '.$site,
    ])->and($root.'/.ai-harness.state.json')->not->toBeFile();
});

test('repeated Herd setup does not reissue a harness-owned certificate', function (): void {
    $root = temp_directory('harness-repeat-secure');
    $fakeBin = $root.'/fake-bin';
    $herdLog = temp_file('herd-repeat-secure-log');
    $site = SiteName::forPath($root);
    mkdir($fakeBin, 0755, true);
    mkdir($root.'/vendor', 0755, true);
    file_put_contents($root.'/vendor/autoload.php', "<?php\n");
    file_put_contents($root.'/.ai-harness.config', "runtime=herd\nservices=none\nagents=\n");
    write_executable($fakeBin.'/herd', <<<'BASH'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "${HERD_LOG}"
BASH);

    $environment = [
        'PATH' => $fakeBin.PATH_SEPARATOR.getenv('PATH'),
        'HERD_LOG' => $herdLog,
    ];

    harness_process(['setup'], $root, $environment)->mustRun();
    harness_process(['setup'], $root, $environment)->mustRun();

    expect(file($herdLog, FILE_IGNORE_NEW_LINES))->toBe([
        'link '.$site.' --no-interaction',
        'secure '.$site,
    ]);
});

test('cleanup refuses a state file that names a Herd site not derived from this project', function (): void {
    $root = temp_directory('harness-cleanup-guard');
    $fakeBin = $root.'/fake-bin';
    $herdLog = temp_file('herd-guard-log');
    mkdir($fakeBin, 0755, true);
    file_put_contents($root.'/.ai-harness.config', "runtime=herd\nservices=none\nagents=\n");
    file_put_contents($root.'/.ai-harness.state.json', json_encode(['herd_site' => 'someone-elses-site'], JSON_THROW_ON_ERROR));
    write_executable($fakeBin.'/herd', <<<'BASH'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "${HERD_LOG}"
BASH);

    $process = harness_process(['cleanup'], $root, [
        'PATH' => $fakeBin.PATH_SEPARATOR.getenv('PATH'),
        'HERD_LOG' => $herdLog,
    ]);
    $process->run();

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput().$process->getOutput())->toContain('Refusing to unlink unexpected Herd site')
        ->and(trim((string) file_get_contents($herdLog)))->toBe('')
        ->and($root.'/.ai-harness.state.json')->toBeFile();
});

test('an unsecured first-time Herd setup does not emit TLS commands or warnings', function (): void {
    $root = temp_directory('harness-unsecured-herd');
    $fakeBin = $root.'/fake-bin';
    $herdLog = temp_file('herd-unsecured-log');
    mkdir($fakeBin, 0755, true);
    mkdir($root.'/vendor', 0755, true);
    file_put_contents($root.'/vendor/autoload.php', "<?php\n");
    file_put_contents($root.'/.ai-harness.config', implode("\n", [
        'runtime=herd',
        'services=none',
        'agents=',
        'herd_secure=false',
        '',
    ]));
    write_executable($fakeBin.'/herd', <<<'BASH'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "${HERD_LOG}"
BASH);

    $process = harness_process(['setup'], $root, [
        'PATH' => $fakeBin.PATH_SEPARATOR.getenv('PATH'),
        'HERD_LOG' => $herdLog,
    ]);
    $process->mustRun();

    $site = SiteName::forPath($root);

    expect($process->getErrorOutput())->toBe('')
        ->and(file($herdLog, FILE_IGNORE_NEW_LINES))->toBe(['link '.$site.' --no-interaction'])
        ->and(json_decode((string) file_get_contents($root.'/.ai-harness.state.json'), true, flags: JSON_THROW_ON_ERROR))
        ->toBe(['herd_site' => $site]);
});

test('setup does not run migrations or alter test configuration', function (): void {
    $root = temp_directory('harness-no-migrations');
    mkdir($root.'/vendor', 0755, true);
    file_put_contents($root.'/vendor/autoload.php', "<?php\n");
    file_put_contents($root.'/.ai-harness.config', "runtime=native\nservices=none\nagents=\n");
    file_put_contents($root.'/phpunit.xml', '<phpunit/>');

    harness_process(['setup'], $root)->mustRun();

    expect(file_get_contents($root.'/phpunit.xml'))->toBe('<phpunit/>')
        ->and($root.'/.ai-harness.phpunit.xml')->not->toBeFile();
});
