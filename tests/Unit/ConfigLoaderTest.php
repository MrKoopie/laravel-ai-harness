<?php

declare(strict_types=1);

use MrKoopie\LaravelAiHarness\Config\ConfigException;
use MrKoopie\LaravelAiHarness\Config\ConfigLoader;
use MrKoopie\LaravelAiHarness\Environment\Runtime;
use MrKoopie\LaravelAiHarness\Environment\Services;
use Symfony\Component\Process\Process;

test('configuration layers from dist through shared and local files', function (): void {
    $root = temp_directory('harness-config');

    file_put_contents($root.'/.ai-harness.config.dist', implode("\n", [
        'runtime=native',
        'services=none',
        'agents=codex',
        'worktrees=false',
    ]));
    file_put_contents($root.'/.ai-harness.config', implode("\n", [
        'runtime=herd',
        'agents=codex,claude',
        'herd_php="8.4"',
    ]));
    file_put_contents($root.'/.ai-harness.config.local', implode("\n", [
        'services=sail',
        'sail_services=mysql, redis',
        'worktrees=on',
    ]));

    $config = (new ConfigLoader)->load($root);

    expect($config->runtime)->toBe(Runtime::Herd)
        ->and($config->services)->toBe(Services::Sail)
        ->and($config->agents)->toBe(['codex', 'claude'])
        ->and($config->sailServices)->toBe(['mysql', 'redis'])
        ->and($config->herdPhp)->toBe('8.4')
        ->and($config->worktrees)->toBeTrue()
        ->and($config->sourceFiles)->toBe([
            '.ai-harness.config.dist',
            '.ai-harness.config',
            '.ai-harness.config.local',
        ]);
});

test('configuration reads the Valet runtime and its site options', function (): void {
    $root = temp_directory('harness-config-valet');
    file_put_contents($root.'/.ai-harness.config', implode("\n", [
        'runtime=valet',
        'valet_secure=false',
        'valet_php=8.3',
    ]));

    $config = (new ConfigLoader)->load($root);

    expect($config->runtime)->toBe(Runtime::Valet)
        ->and($config->valetSecure)->toBeFalse()
        ->and($config->valetPhp)->toBe('8.3');
});

test('configuration has conservative internal defaults', function (): void {
    $config = (new ConfigLoader)->load(temp_directory('harness-defaults'));

    expect($config->runtime)->toBe(Runtime::Native)
        ->and($config->services)->toBe(Services::None)
        ->and($config->agents)->toBe(['codex', 'claude'])
        ->and($config->sailServices)->toBe([])
        ->and($config->herdSecure)->toBeTrue()
        ->and($config->herdPhp)->toBeNull()
        ->and($config->valetSecure)->toBeTrue()
        ->and($config->valetPhp)->toBeNull()
        ->and($config->worktrees)->toBeTrue()
        ->and($config->sourceFiles)->toBe([]);
});

test('unknown and duplicate configuration keys fail clearly', function (): void {
    foreach ([
        ['runtim=herd', 'Unknown configuration key [runtim]'],
        ["runtime=herd\nruntime=sail", 'Duplicate configuration key [runtime]'],
        ['herd_secure=perhaps', 'herd_secure must be true or false'],
        ['herd_php=latest', 'herd_php must be empty or a major.minor version'],
        ['valet_secure=perhaps', 'valet_secure must be true or false'],
        ['valet_php=php@8.4', 'valet_php must be empty or a major.minor version'],
        ['agents=codex,cursor', 'Unsupported agent [cursor]'],
        ["services=none\nsail_services=mysql", 'sail_services may only be set when services=sail'],
        ['sail_services=mysql,,redis', 'sail_services contains an empty list item'],
        ['runtime="herd', 'Unterminated quoted value'],
        ['runtime=docker', 'runtime must be one of: native, herd, valet, sail'],
        ['services=podman', 'services must be one of: none, sail'],
        ["services=sail\nsail_services=my db", 'Invalid Sail service name [my db]'],
    ] as [$contents, $message]) {
        $root = temp_directory('harness-invalid');
        file_put_contents($root.'/.ai-harness.config', $contents);

        expect(fn () => (new ConfigLoader)->load($root))->toThrow(ConfigException::class, $message);
    }
});

test('configuration files are size bounded', function (): void {
    $root = temp_directory('harness-large-config');
    file_put_contents($root.'/.ai-harness.config', str_repeat('#', 65_537));

    expect(fn () => (new ConfigLoader)->load($root))
        ->toThrow(ConfigException::class, 'exceeds 64 KiB');
});

test('local environment settings merge individually with source provenance', function (): void {
    $root = temp_directory('harness-env-config');
    file_put_contents($root.'/.ai-harness.config', "local_env.DB_PORT=3306\nlocal_env.REDIS_PORT=6380\n");
    file_put_contents($root.'/.ai-harness.config.local', "local_env.DB_PORT=3307\nlocal_env.CUSTOM_FLAG=false\n");

    $config = (new ConfigLoader)->load($root);

    expect($config->localEnvironment)->toBe(['DB_PORT' => '3307', 'REDIS_PORT' => '6380', 'CUSTOM_FLAG' => 'false'])
        ->and($config->localEnvironmentSources['DB_PORT'])->toBe('.ai-harness.config.local')
        ->and($config->localEnvironmentSources['REDIS_PORT'])->toBe('.ai-harness.config');
});

test('linked worktrees inherit primary local settings before their own overrides', function (): void {
    $primary = temp_directory('harness-primary');
    $worktree = temp_directory('harness-linked');
    (new Process(['git', 'init', '--initial-branch=main', $primary]))->mustRun();
    (new Process(['git', '-c', 'user.name=Harness Test', '-c', 'user.email=harness@example.invalid', 'commit', '--allow-empty', '--no-gpg-sign', '-m', 'Fixture'], $primary))->mustRun();
    (new Process(['git', 'update-ref', 'refs/remotes/origin/main', 'HEAD'], $primary))->mustRun();
    (new Process(['git', 'worktree', 'add', '--detach', $worktree, 'origin/main'], $primary))->mustRun();
    file_put_contents($primary.'/.ai-harness.config.local', "local_env.DB_PORT=3307\nlocal_env.MAIL_PORT=1026\nruntime=valet\n");
    file_put_contents($worktree.'/.ai-harness.config', "runtime=native\nlocal_env.MAIL_PORT=1025\n");

    try {
        $loader = new ConfigLoader;
        $inherited = $loader->load($worktree);
        file_put_contents($worktree.'/.ai-harness.config.local', "runtime=native\nlocal_env.DB_PORT=3308\n");
        $config = $loader->load($worktree);

        expect($inherited->runtime)->toBe(Runtime::Valet)
            ->and($inherited->localEnvironment)->toBe(['MAIL_PORT' => '1026', 'DB_PORT' => '3307'])
            ->and($config->runtime)->toBe(Runtime::Native)
            ->and($config->localEnvironment)->toBe(['MAIL_PORT' => '1026', 'DB_PORT' => '3308'])
            ->and($config->localEnvironmentSources['DB_PORT'])->toBe('.ai-harness.config.local')
            ->and($config->localEnvironmentSources['MAIL_PORT'])->toBe($primary.'/.ai-harness.config.local')
            ->and($loader->load($primary)->localEnvironmentSources['DB_PORT'])->toBe('.ai-harness.config.local');
    } finally {
        (new Process(['git', 'worktree', 'remove', '--force', $worktree], $primary))->mustRun();
    }
});

test('metadata without a matching worktree registration cannot inherit outside configuration', function (): void {
    $primary = temp_directory('harness-unregistered-primary');
    $root = temp_directory('harness-unregistered-linked');
    $metadata = $primary.'/.git/worktrees/fake';
    mkdir($metadata, 0755, true);
    file_put_contents($metadata.'/commondir', '../..');
    file_put_contents($metadata.'/gitdir', '/unrelated/.git');
    file_put_contents($root.'/.git', 'gitdir: '.$metadata);
    file_put_contents($primary.'/.ai-harness.config.local', 'local_env.DB_PORT=3307');

    expect((new ConfigLoader)->load($root)->localEnvironment)->toBe([]);
});

foreach ([
    ['local_env.db_port=3307', 'Unknown configuration key'],
    ['local_env.DB_PORT=65536', 'TCP port'],
    ['local_env.FORWARD_DB_PORT=oops', 'TCP port'],
    ["local_env.CUSTOM_VALUE=can't `expand`", 'cannot combine backticks'],
    ["local_env.CUSTOM_VALUE=a\0b", 'control character'],
    ["local_env.DB_PORT=3307\nlocal_env.DB_PORT=3308", 'Duplicate configuration key'],
    ['local_env.APP_KEY=secret', 'managed'],
    ['local_env.APP_ENV=production', 'managed'],
    ['local_env.APP_CONFIG_CACHE=/outside.php', 'managed'],
    ["runtime=herd\nlocal_env.APP_URL=https://shared.test", 'managed'],
    ["services=sail\nsail_services=mysql\nlocal_env.DB_DATABASE=shared", 'managed'],
    ["services=sail\nsail_services=mysql\nlocal_env.DB_PORT=3307", 'FORWARD_DB_PORT'],
] as [$contents, $message]) {
    test('unsafe or conflicting local override fails: '.$contents, function () use ($contents, $message): void {
        $root = temp_directory('harness-env-invalid');
        file_put_contents($root.'/.ai-harness.config.local', $contents);

        expect(fn () => (new ConfigLoader)->load($root))->toThrow(ConfigException::class, $message);
    });
}
