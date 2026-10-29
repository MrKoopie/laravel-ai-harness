<?php

declare(strict_types=1);

use MrKoopie\LaravelAiHarness\Config\ConfigException;
use MrKoopie\LaravelAiHarness\Config\ConfigLoader;
use Symfony\Component\Process\Process;

foreach ([
    ['native', '', null, null],
    ['native', 'mysql,redis', 'up -d mysql redis', 'stop mysql redis'],
    ['herd', 'mysql,redis', 'up -d mysql redis', 'stop mysql redis'],
    ['yerd', 'mysql,redis', 'up -d mysql redis', 'stop mysql redis'],
    ['valet', 'redis', 'up -d redis', 'stop redis'],
    ['sail', '', 'up -d', 'down'],
    ['sail', 'mysql,redis', 'up -d laravel.test mysql redis', 'stop laravel.test mysql redis'],
] as [$runtime, $services, $up, $down]) {
    test("Sail lifecycle is inferred for {$runtime} with services [{$services}]", function () use ($runtime, $services, $up, $down): void {
        $root = temp_directory('harness-sail-inference');
        file_put_contents($root.'/.ai-harness.config', "runtime={$runtime}\nsail_services={$services}\nagents=\n");
        write_executable($root.'/vendor/bin/sail', "#!/bin/sh\nprintf '%s\\n' \"\$*\" >> sail-log\n");
        harness_process(['up'], $root)->mustRun();
        harness_process(['down'], $root)->mustRun();
        if ($up === null) {
            expect($root.'/sail-log')->not->toBeFile();
        } else {
            expect(file_get_contents($root.'/sail-log'))->toBe($up."\n".$down."\n");
        }
    });
}

test('full Sail stack can explicitly select isolated MySQL without listing services', function (): void {
    $root = temp_directory('harness-full-sail-mysql');
    file_put_contents($root.'/.ai-harness.config', "runtime=sail\ndb_connection=mysql\n");
    $config = (new ConfigLoader)->load($root);
    expect($config->managesMySql())->toBeTrue()
        ->and($config->usesHostMySql())->toBeFalse();
});

test('host PHP with an empty service list needs no Sail installation and ignores the removed variable', function (): void {
    $root = temp_directory('harness-no-sail');
    file_put_contents($root.'/.ai-harness.config', "runtime=native\nagents=\n");
    $env = ['AI_HARNESS_SERVICE_MANAGER' => 'sail'];
    harness_process(['up'], $root, $env)->mustRun();
    harness_process(['down'], $root, $env)->mustRun();
    expect((new ConfigLoader)->load($root)->usesSail())->toBeFalse();
});

test('full Sail stack does not assume MySQL and a MySQL subset must include it', function (): void {
    $root = temp_directory('harness-sail-database-selection');
    file_put_contents($root.'/.ai-harness.config', "runtime=sail\n");
    expect((new ConfigLoader)->load($root)->managesMySql())->toBeFalse();
    file_put_contents($root.'/.ai-harness.config', "runtime=sail\nsail_services=redis\ndb_connection=mysql\n");
    expect(fn () => (new ConfigLoader)->load($root))->toThrow(ConfigException::class, 'A Sail MySQL subset must include mysql');
});

test('the removed service manager option is rejected before generating files', function (): void {
    $root = temp_directory('harness-removed-manager-option');
    $process = harness_process(['init', '--service-manager=sail'], $root);
    $process->run();
    expect($process->getExitCode())->not->toBe(0)
        ->and($process->getErrorOutput())->toContain('service-manager')
        ->and($root.'/.ai-harness.config')->not->toBeFile();
});

foreach ([false, true] as $installed) {
    test('SQLite setup bootstraps and starts Sail before opening the database with dependencies '.($installed ? 'installed' : 'missing'), function () use ($installed): void {
        $root = temp_directory('harness-sail-sqlite-startup');
        file_put_contents($root.'/.env.example', "APP_KEY=existing\nDB_CONNECTION=sqlite\n");
        file_put_contents($root.'/.ai-harness.config', "runtime=sail\ndb_connection=sqlite\nagents=\n");
        $sail = <<<'BASH'
#!/usr/bin/env bash
set -e
printf '%s\n' "$1" >> lifecycle-log
if [[ "$1" == up ]]; then touch sail-running; exit 0; fi
[[ -f sail-running ]] || exit 42
if [[ "$1" == php ]]; then shift; exec "$HARNESS_PHP" "$@"; fi
BASH;
        write_executable($root.'/sail-fixture', $sail);
        if ($installed) {
            write_executable($root.'/vendor/bin/sail', $sail);
            file_put_contents($root.'/vendor/autoload.php', '<?php');
        }
        write_executable($root.'/bin/composer', <<<'BASH'
#!/usr/bin/env bash
set -e
printf 'composer\n' >> lifecycle-log
mkdir -p vendor/bin
cp sail-fixture vendor/bin/sail
printf '<?php' > vendor/autoload.php
BASH);
        harness_process(['setup'], $root, ['PATH' => $root.'/bin'.PATH_SEPARATOR.getenv('PATH'), 'HARNESS_PHP' => PHP_BINARY])->mustRun();
        expect(file($root.'/lifecycle-log', FILE_IGNORE_NEW_LINES))->toBe($installed ? ['up', 'php'] : ['composer', 'up', 'php'])
            ->and($root.'/database/database.sqlite')->toBeFile()
            ->and(file_get_contents($root.'/.env'))->toContain('DB_DATABASE=database/database.sqlite');
    });
}

foreach ([false, true] as $existing) {
    test('SQLite process conflicts preserve all environment files with an '.($existing ? 'existing' : 'absent').' env', function () use ($existing): void {
        $root = temp_directory('harness-sqlite-preflight');
        $contents = "APP_NAME=unchanged\nDB_CONNECTION=sqlite\n";
        file_put_contents($root.'/.env.example', $contents);
        file_put_contents($root.'/.env.testing', $contents);
        if ($existing) {
            file_put_contents($root.'/.env', $contents);
        }
        file_put_contents($root.'/.ai-harness.config', "runtime=native\ndb_connection=sqlite\nagents=\nlocal_env.APP_NAME=changed\n");
        $process = harness_process(['setup'], $root, ['DB_HOST' => '127.0.0.1']);
        $process->run();
        expect($process->getExitCode())->toBe(1)
            ->and($process->getErrorOutput())->toContain('Unset process DB_HOST')
            ->and(file_get_contents($root.'/.env.example'))->toBe($contents)
            ->and(file_get_contents($root.'/.env.testing'))->toBe($contents);
        if ($existing) {
            expect(file_get_contents($root.'/.env'))->toBe($contents);
        } else {
            expect($root.'/.env')->not->toBeFile();
        }
    });
}

test('SQLite without DOM fails before copying environment files or creating its database', function (): void {
    $root = temp_directory('harness-sqlite-no-dom');
    file_put_contents($root.'/.env.example', "DB_CONNECTION=sqlite\n");
    file_put_contents($root.'/.ai-harness.config', "runtime=native\ndb_connection=sqlite\nagents=\n");
    file_put_contents($root.'/phpunit.xml', '<phpunit/>');
    $script = $root.'/without-dom.php';
    $autoload = var_export(package_root().'/vendor/autoload.php', true);
    $project = var_export($root, true);
    file_put_contents($script, '<?php namespace MrKoopie\\LaravelAiHarness\\Environment { function class_exists(string $name): bool { return $name !== \\DOMDocument::class && \\class_exists($name); } } namespace { require '.$autoload.'; exit((new \\MrKoopie\\LaravelAiHarness\\Application)->run(new \\Symfony\\Component\\Console\\Input\\ArrayInput(["command" => "setup", "--path" => '.$project.']))); }');
    $process = new Process([PHP_BINARY, $script]);
    $process->run();
    expect($process->getExitCode())->toBe(1)
        ->and($process->getOutput().$process->getErrorOutput())->toContain('ext-dom')
        ->and($root.'/.env')->not->toBeFile()
        ->and($root.'/.env.testing')->not->toBeFile()
        ->and($root.'/database')->not->toBeDirectory()
        ->and(file_get_contents($root.'/phpunit.xml'))->toBe('<phpunit/>');
});

foreach ([['sail', 'mysql'], ['native', 'mysql'], ['sail', '']] as [$runtime, $services]) {
    test('MariaDB selection rejects unsupported Sail provisioning for '.$runtime.' with ['.$services.']', function () use ($runtime, $services): void {
        $root = temp_directory('harness-sail-mariadb-conflict');
        file_put_contents($root.'/.ai-harness.config', "runtime={$runtime}\nsail_services={$services}\ndb_connection=mariadb\n");
        expect(fn () => (new ConfigLoader)->load($root))->toThrow(ConfigException::class, 'Sail-managed databases use the mysql service');
    });

}
