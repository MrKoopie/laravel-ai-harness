<?php

declare(strict_types=1);

use MrKoopie\LaravelAiHarness\Config\Config;
use MrKoopie\LaravelAiHarness\Environment\CommandFactory;
use MrKoopie\LaravelAiHarness\Environment\Runtime;
use MrKoopie\LaravelAiHarness\Environment\Services;
use MrKoopie\LaravelAiHarness\Process\ExecutableLocator;

/** @param list<non-empty-string> $sailServices */
function harness_config(Runtime $runtime, Services $services = Services::None, array $sailServices = []): Config
{
    return new Config(
        runtime: $runtime,
        services: $services,
        agents: ['codex', 'claude'],
        sailServices: $sailServices,
        herdSecure: true,
        herdPhp: null,
        worktrees: true,
        sourceFiles: ['.ai-harness.config'],
    );
}

test('runtime commands are built as argv arrays without shell interpolation', function (): void {
    $root = temp_directory('harness-command');
    mkdir($root.'/vendor/bin', 0755, true);
    write_executable($root.'/vendor/bin/sail', "#!/usr/bin/env bash\nexit 0\n");

    $factory = new CommandFactory(new ExecutableLocator(overrides: [
        'php' => '/tools/php',
        'composer' => '/tools/composer',
        'herd' => '/tools/herd',
        'npm' => '/tools/npm',
    ]));
    $arguments = ['migrate', '--path=value with spaces', '$(touch nope)'];

    expect($factory->runtime(harness_config(Runtime::Native), 'artisan', $arguments, $root))
        ->toBe(['/tools/php', $root.'/artisan', ...$arguments])
        ->and($factory->runtime(harness_config(Runtime::Herd), 'artisan', $arguments, $root))
        ->toBe(['/tools/herd', 'php', $root.'/artisan', ...$arguments])
        ->and($factory->runtime(harness_config(Runtime::Sail), 'artisan', $arguments, $root))
        ->toBe([$root.'/vendor/bin/sail', 'artisan', ...$arguments]);
});

test('sail services can start and stop a selected subset', function (): void {
    $root = temp_directory('harness-services');
    mkdir($root.'/vendor/bin', 0755, true);
    write_executable($root.'/vendor/bin/sail', "#!/usr/bin/env bash\nexit 0\n");
    $factory = new CommandFactory(new ExecutableLocator);
    $config = harness_config(Runtime::Herd, Services::Sail, ['mysql', 'redis']);

    expect($factory->servicesUp($config, $root))
        ->toBe([$root.'/vendor/bin/sail', 'up', '-d', 'mysql', 'redis'])
        ->and($factory->servicesDown($config, $root))
        ->toBe([$root.'/vendor/bin/sail', 'stop', 'mysql', 'redis']);
});

test('an empty sail service list controls the full stack', function (): void {
    $root = temp_directory('harness-full-stack');
    mkdir($root.'/vendor/bin', 0755, true);
    write_executable($root.'/vendor/bin/sail', "#!/usr/bin/env bash\nexit 0\n");
    $factory = new CommandFactory(new ExecutableLocator);
    $config = harness_config(Runtime::Sail, Services::Sail);

    expect($factory->servicesUp($config, $root))
        ->toBe([$root.'/vendor/bin/sail', 'up', '-d'])
        ->and($factory->servicesDown($config, $root))
        ->toBe([$root.'/vendor/bin/sail', 'down']);
});

test('Sail runtime always starts its application container', function (): void {
    $root = temp_directory('harness-sail-runtime');
    mkdir($root.'/vendor/bin', 0755, true);
    write_executable($root.'/vendor/bin/sail', "#!/usr/bin/env bash\nexit 0\n");
    $factory = new CommandFactory(new ExecutableLocator);

    expect($factory->servicesUp(harness_config(Runtime::Sail), $root))
        ->toBe([$root.'/vendor/bin/sail', 'up', '-d', 'laravel.test'])
        ->and($factory->servicesDown(harness_config(Runtime::Sail), $root))
        ->toBe([$root.'/vendor/bin/sail', 'stop', 'laravel.test'])
        ->and($factory->servicesUp(harness_config(Runtime::Sail, Services::Sail, ['mysql', 'redis']), $root))
        ->toBe([$root.'/vendor/bin/sail', 'up', '-d', 'laravel.test', 'mysql', 'redis'])
        ->and($factory->servicesDown(harness_config(Runtime::Sail, Services::Sail, ['mysql', 'redis']), $root))
        ->toBe([$root.'/vendor/bin/sail', 'stop', 'laravel.test', 'mysql', 'redis']);
});

test('bootstrap composer prefers composer and then herd', function (): void {
    $composerFactory = new CommandFactory(new ExecutableLocator(overrides: [
        'composer' => '/tools/composer',
        'herd' => '/tools/herd',
    ]));
    $herdFactory = new CommandFactory(new ExecutableLocator(overrides: [
        'composer' => null,
        'herd' => '/tools/herd',
    ]));

    expect($composerFactory->bootstrapComposer())
        ->toBe(['/tools/composer', 'install', '--no-interaction', '--prefer-dist'])
        ->and($herdFactory->bootstrapComposer())
        ->toBe(['/tools/herd', 'composer', 'install', '--no-interaction', '--prefer-dist']);
});
