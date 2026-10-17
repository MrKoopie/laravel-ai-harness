<?php

declare(strict_types=1);

use MrKoopie\LaravelAiHarness\Config\ConfigException;
use MrKoopie\LaravelAiHarness\Config\ConfigLoader;

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
