<?php

declare(strict_types=1);
use MrKoopie\LaravelAiHarness\Environment\DatabaseName;
use MrKoopie\LaravelAiHarness\Environment\SiteName;

/** @return list<string> */
function environment_log_lines(string $path): array
{
    $lines = file($path, FILE_IGNORE_NEW_LINES);

    if ($lines === false) {
        throw new RuntimeException("Unable to read test log [{$path}].");
    }

    return $lines;
}

test('setup composes Herd with selected Sail services and cleanup only unlinks owned Herd state', function (): void {
    $root = temp_directory('harness-environment');
    $fakeBin = $root.'/fake-bin';
    $herdLog = temp_file('herd-log');
    $sailLog = temp_file('sail-log');
    mkdir($fakeBin, 0755, true);
    mkdir($root.'/vendor/bin', 0755, true);
    file_put_contents($root.'/vendor/autoload.php', "<?php\n");
    file_put_contents($root.'/artisan', "<?php\n");
    file_put_contents($root.'/.env.example', "APP_KEY=\nFORWARD_DB_PORT=3310\nDB_CONNECTION=mysql\n# DB_HOST=127.0.0.1\n# DB_PORT=3306\n# DB_DATABASE=laravel\n# DB_USERNAME=root\n# DB_PASSWORD=\n");
    file_put_contents($root.'/phpunit.xml', '<phpunit><php><env name="DB_CONNECTION" value="sqlite"/><env name="DB_DATABASE" value=":memory:"/></php></phpunit>');
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
    $database = DatabaseName::forPath($root);
    $testingDatabase = DatabaseName::testingForPath($root);

    expect($state)->toBe(['mysql_databases' => true, 'herd_site' => $site, 'herd_secured' => true])
        ->and(environment_log_lines($sailLog)[0])->toBe('up -d mysql redis')
        ->and(environment_log_lines($sailLog)[1])->toStartWith('exec -T mysql bash -c for attempt')
        ->and(file($herdLog, FILE_IGNORE_NEW_LINES))->toBe([
            'link '.$site.' --no-interaction',
            'secure '.$site,
            'isolate 8.4',
            'php '.$root.'/artisan key:generate --ansi',
        ])
        ->and($root.'/.env')->toBeFile()
        ->and($root.'/.env.testing')->toBeFile()
        ->and((string) file_get_contents($root.'/.env'))->toContain('APP_URL=https://'.$site.'.test')
        ->and((string) file_get_contents($root.'/.env.testing'))->toContain('APP_ENV=testing')
        ->and((string) file_get_contents($root.'/.env.testing'))->toContain('DB_CONNECTION=mysql')
        ->and((string) file_get_contents($root.'/.env.testing'))->toContain('DB_HOST=127.0.0.1')
        ->and((string) file_get_contents($root.'/.env.testing'))->toContain('DB_PORT=3310')
        ->and((string) file_get_contents($root.'/.env.testing'))->toContain('DB_DATABASE='.$testingDatabase)
        ->and((string) file_get_contents($root.'/.env.testing'))->toContain('CACHE_STORE=array')
        ->and((string) file_get_contents($root.'/.env.testing'))->toContain('SESSION_DRIVER=array')
        ->and((string) file_get_contents($root.'/.env.testing'))->toContain('QUEUE_CONNECTION=sync')
        ->and((string) file_get_contents($root.'/.env'))->toContain('DB_CONNECTION=mysql')
        ->and((string) file_get_contents($root.'/.env'))->toContain('DB_HOST=127.0.0.1')
        ->and((string) file_get_contents($root.'/.env'))->toContain('DB_PORT=3310')
        ->and((string) file_get_contents($root.'/.env'))->toContain('DB_DATABASE='.$database)
        ->and((string) file_get_contents($root.'/phpunit.xml'))->toContain('name="DB_CONNECTION" value="mysql"')
        ->and((string) file_get_contents($root.'/phpunit.xml'))->toContain('name="DB_DATABASE" value="'.$testingDatabase.'"')
        ->and(substr_count((string) file_get_contents($root.'/.env'), 'DB_HOST='))->toBe(1)
        ->and(substr_count((string) file_get_contents($root.'/.env.testing'), 'DB_HOST='))->toBe(1);

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
        ->and(environment_log_lines($sailLog))->toHaveCount(3)
        ->and(environment_log_lines($sailLog)[2])->toContain('DROP DATABASE IF EXISTS `'.$database.'`');

    harness_process(['down'], $root, $environment)->mustRun();

    expect(environment_log_lines($sailLog))->toBe([
        'up -d mysql redis',
        environment_log_lines($sailLog)[1],
        environment_log_lines($sailLog)[2],
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

        $commands = environment_log_lines($sailLog);

        if (str_contains($sailServices, 'mysql')) {
            expect($commands[0])->toBe($expectedSetup)
                ->and($commands[1])->toStartWith('exec -T mysql bash -c for attempt')
                ->and($commands[2])->toBe($expectedSetup)
                ->and($commands[3])->toBe($expectedDown);
        } else {
            expect($commands)->toBe([
                $expectedSetup,
                $expectedSetup,
                $expectedDown,
            ]);
        }
    }
});

test('setup normalizes existing Laravel test environment to checkout-specific Sail MySQL values', function (): void {
    $root = temp_directory('harness-existing-testing-environment');
    $sailLog = temp_file('sail-existing-testing-environment-log');
    mkdir($root.'/vendor/bin', 0755, true);
    file_put_contents($root.'/vendor/autoload.php', "<?php\n");
    file_put_contents($root.'/artisan', "<?php\n");
    file_put_contents($root.'/.env', "APP_KEY=present\nFORWARD_DB_PORT=3310\nDB_CONNECTION=sqlite\n");
    file_put_contents($root.'/.env.testing', "APP_ENV=testing\nDB_CONNECTION=sqlite\nDB_DATABASE=:memory:\n");
    file_put_contents($root.'/phpunit.xml', '<phpunit><php><env name="DB_CONNECTION" value="sqlite"/><env name="DB_DATABASE" value=":memory:"/></php></phpunit>');
    file_put_contents($root.'/.ai-harness.config', "runtime=herd\nservices=sail\nagents=\nsail_services=mysql\n");
    write_executable($root.'/vendor/bin/sail', <<<'BASH'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "${SAIL_LOG}"
BASH);
    $fakeBin = $root.'/fake-bin';
    mkdir($fakeBin, 0755, true);
    write_executable($fakeBin.'/herd', <<<'BASH'
#!/usr/bin/env bash
exit 0
BASH);

    harness_process(['setup'], $root, [
        'PATH' => $fakeBin.PATH_SEPARATOR.getenv('PATH'),
        'SAIL_LOG' => $sailLog,
    ])->mustRun();

    $database = DatabaseName::forPath($root);
    $testingDatabase = DatabaseName::testingForPath($root);

    expect((string) file_get_contents($root.'/.env.testing'))
        ->toContain('DB_CONNECTION=mysql')
        ->toContain('DB_HOST=127.0.0.1')
        ->toContain('DB_PORT=3310')
        ->toContain('DB_DATABASE='.$testingDatabase)
        ->and((string) file_get_contents($root.'/.env'))->toContain('DB_PORT=3310')
        ->and((string) file_get_contents($root.'/.env'))->toContain('DB_DATABASE='.$database)
        ->and((string) file_get_contents($root.'/phpunit.xml'))->toContain('name="DB_CONNECTION" value="mysql"')
        ->and((string) file_get_contents($root.'/phpunit.xml'))->toContain('name="DB_DATABASE" value="'.$testingDatabase.'"');
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

test('cleanup removes harness-owned MySQL databases without a Herd site', function (): void {
    $root = temp_directory('harness-mysql-cleanup');
    $sailLog = temp_file('sail-mysql-cleanup-log');
    mkdir($root.'/vendor/bin', 0755, true);
    file_put_contents($root.'/vendor/autoload.php', "<?php\n");
    file_put_contents($root.'/.ai-harness.config', "runtime=native\nservices=sail\nagents=\nsail_services=mysql\n");
    file_put_contents($root.'/.ai-harness.state.json', '{"mysql_databases":true}');
    write_executable($root.'/vendor/bin/sail', <<<'BASH'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "${SAIL_LOG}"
BASH);

    harness_process(['cleanup'], $root, ['SAIL_LOG' => $sailLog])->mustRun();

    expect($root.'/.ai-harness.state.json')->not->toBeFile()
        ->and(environment_log_lines($sailLog))->toHaveCount(1)
        ->and(environment_log_lines($sailLog)[0])->toContain('DROP DATABASE IF EXISTS `'.DatabaseName::forPath($root).'`')
        ->and(environment_log_lines($sailLog)[0])->toContain('DROP DATABASE IF EXISTS `'.DatabaseName::testingForPath($root).'`');
});

test('cleanup migrates legacy Herd state to MySQL database cleanup', function (): void {
    $root = temp_directory('harness-legacy-mysql-cleanup');
    $sailLog = temp_file('sail-legacy-mysql-cleanup-log');
    $herdLog = temp_file('herd-legacy-mysql-cleanup-log');
    $fakeBin = $root.'/fake-bin';
    $site = SiteName::forPath($root);
    mkdir($fakeBin, 0755, true);
    mkdir($root.'/vendor/bin', 0755, true);
    file_put_contents($root.'/vendor/autoload.php', "<?php\n");
    file_put_contents($root.'/.ai-harness.config', "runtime=herd\nservices=sail\nagents=\nsail_services=mysql\n");
    file_put_contents($root.'/.ai-harness.state.json', json_encode(['herd_site' => $site], JSON_THROW_ON_ERROR));
    write_executable($root.'/vendor/bin/sail', <<<'BASH'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "${SAIL_LOG}"
BASH);
    write_executable($fakeBin.'/herd', <<<'BASH'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "${HERD_LOG}"
BASH);

    harness_process(['cleanup'], $root, [
        'PATH' => $fakeBin.PATH_SEPARATOR.getenv('PATH'),
        'SAIL_LOG' => $sailLog,
        'HERD_LOG' => $herdLog,
    ])->mustRun();

    expect($root.'/.ai-harness.state.json')->not->toBeFile()
        ->and(environment_log_lines($sailLog)[0])->toContain('DROP DATABASE IF EXISTS `'.DatabaseName::forPath($root).'`')
        ->and(file($herdLog, FILE_IGNORE_NEW_LINES))->toBe(['unlink '.$site]);
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

test('setup does not run migrations or alter PHPUnit configuration outside Sail MySQL', function (): void {
    $root = temp_directory('harness-no-migrations');
    mkdir($root.'/vendor', 0755, true);
    file_put_contents($root.'/vendor/autoload.php', "<?php\n");
    file_put_contents($root.'/.ai-harness.config', "runtime=native\nservices=none\nagents=\n");
    file_put_contents($root.'/phpunit.xml', '<phpunit/>');

    harness_process(['setup'], $root)->mustRun();

    expect(file_get_contents($root.'/phpunit.xml'))->toBe('<phpunit/>')
        ->and($root.'/.ai-harness.phpunit.xml')->not->toBeFile();
});
