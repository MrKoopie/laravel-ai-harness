<?php

declare(strict_types=1);

use MrKoopie\LaravelAiHarness\Config\ConfigException;
use MrKoopie\LaravelAiHarness\Config\ConfigLoader;
use MrKoopie\LaravelAiHarness\Environment\Runtime;

test('process overrides win over every file layer without changing files', function (): void {
    $root = temp_directory('harness-process-config');
    $contents = "runtime=herd\nphp_version=8.3\nsite_secure=true\nsail_services=redis\n";
    foreach (['.ai-harness.config.dist', '.ai-harness.config', '.ai-harness.config.global', '.ai-harness.config.local'] as $file) {
        file_put_contents($root.'/'.$file, $contents);
    }
    putenv('AI_HARNESS_RUNTIME=yerd');
    putenv('AI_HARNESS_PHP_VERSION=8.4');
    putenv('AI_HARNESS_SITE_SECURE=off');
    putenv('AI_HARNESS_SAIL_SERVICES=');
    $loader = new ConfigLoader;
    $config = $loader->load($root);
    expect($config->runtime)->toBe(Runtime::Yerd)
        ->and($config->phpVersion)->toBe('8.4')
        ->and($config->siteSecure)->toBeFalse();
    foreach (['runtime' => 'RUNTIME', 'php_version' => 'PHP_VERSION', 'site_secure' => 'SITE_SECURE', 'sail_services' => 'SAIL_SERVICES'] as $key => $suffix) {
        expect($config->sources[$key])->toBe('AI_HARNESS_'.$suffix);
        putenv('AI_HARNESS_'.$suffix);
    }
    foreach ($config->sourceFiles as $file) {
        expect(file_get_contents($root.'/'.$file))->toBe($contents);
    }
    $fileConfig = $loader->load($root);
    expect($fileConfig->runtime)->toBe(Runtime::Herd)
        ->and($fileConfig->phpVersion)->toBe('8.3')
        ->and($fileConfig->siteSecure)->toBeTrue()
        ->and($fileConfig->sources['runtime'])->toBe('.ai-harness.config.local');
});

foreach ([
    ['AI_HARNESS_RUNTIME', '', 'runtime must be one of'],
    ['AI_HARNESS_RUNTIME', 'unknown', 'runtime must be one of'],
    ['AI_HARNESS_SAIL_SERVICES', 'mysql,,redis', 'sail_services contains an empty list item'],
    ['AI_HARNESS_SITE_SECURE', '', 'site_secure must be true or false'],
    ['AI_HARNESS_SITE_SECURE', 'perhaps', 'site_secure must be true or false'],
    ['AI_HARNESS_PHP_VERSION', '8.4.1', 'php_version must be empty or a major.minor'],
    ['AI_HARNESS_PHP_VERSION', "8.4\n", 'contains a control character'],
    ['AI_HARNESS_PHP_VERSION', str_repeat('8', 4097), 'exceeds 4096 bytes'],
] as [$name, $value, $message]) {
    test("process override {$name} validates ".substr(var_export($value, true), 0, 40), function () use ($name, $value, $message): void {
        putenv($name.'='.$value);
        expect(fn () => (new ConfigLoader)->load(temp_directory('harness-process-invalid')))
            ->toThrow(ConfigException::class, $message);
    });
}

test('empty PHP override clears a file pin and any existing major minor version is accepted', function (): void {
    $root = temp_directory('harness-process-php');
    file_put_contents($root.'/.ai-harness.config', "php_version=8.4\n");
    foreach (['' => null, '8.2' => '8.2', '8.5' => '8.5'] as $value => $expected) {
        putenv('AI_HARNESS_PHP_VERSION='.$value);
        expect((new ConfigLoader)->load($root)->phpVersion)->toBe($expected);
    }
});

test('effective runtime overrides also govern cross setting validation', function (): void {
    $root = temp_directory('harness-process-cross-validation');
    file_put_contents($root.'/.ai-harness.config', "runtime=native\nlocal_env.APP_URL=https://shared.test\n");
    putenv('AI_HARNESS_RUNTIME=yerd');
    expect(fn () => (new ConfigLoader)->load($root))->toThrow(ConfigException::class, 'local_env.APP_URL is managed');
    file_put_contents($root.'/.ai-harness.config', "sail_services=mysql\n");
    putenv('AI_HARNESS_SAIL_SERVICES=redis');
    expect((new ConfigLoader)->load($root)->sailServices)->toBe(['redis']);
});

test('local Sail selections remain independent of cloud service selections', function (): void {
    $root = temp_directory('harness-cloud-process-config');
    file_put_contents($root.'/.ai-harness.config', "runtime=sail\nsail_services=mysql\n");
    putenv('AI_HARNESS_SAIL_SERVICES=redis');
    foreach (['codex-cloud', 'claude-cloud'] as $mode) {
        putenv('AI_HARNESS_ENV='.$mode);
        $config = (new ConfigLoader)->load($root);
        expect($config->sailServices)->toBe(['redis'])
            ->and($config->sources['sail_services'])->toBe('AI_HARNESS_SAIL_SERVICES')
            ->and($config->cloudServices)->toBe(['mysql']);
    }
});

test('database and agent process selections apply without persisting the exports', function (): void {
    $root = temp_directory('harness-database-environment');
    putenv('AI_HARNESS_DB_CONNECTION=mysql');
    putenv('AI_HARNESS_AGENTS=codex');
    $config = (new ConfigLoader)->load($root);
    expect($config->usesHostMySql())->toBeTrue()
        ->and($config->agents)->toBe(['codex'])
        ->and($config->sources['db_connection'])->toBe('AI_HARNESS_DB_CONNECTION')
        ->and($root.'/.ai-harness.config')->not->toBeFile();
});

test('cloud database selection remains independent of local Sail relationships', function (): void {
    $root = temp_directory('harness-cloud-database-environment');
    putenv('AI_HARNESS_ENV=codex-cloud');
    putenv('AI_HARNESS_RUNTIME=sail');
    putenv('AI_HARNESS_DB_CONNECTION=mysql');
    expect((new ConfigLoader)->load($root)->cloudServices)->toBe(['mysql']);
});

test('local parameter and process overrides merge per variable and remain local', function (): void {
    $root = temp_directory('harness-local-process-parameters');
    $contents = "local_env.SHARED=file\nlocal_env.FILE_ONLY=keep\n";
    file_put_contents($root.'/.ai-harness.config', $contents);
    putenv('AI_HARNESS_LOCAL_ENV={"SHARED":"process","PROCESS_ONLY":"keep","CLEARED":""}');
    $config = (new ConfigLoader)->load($root, ['local_env.SHARED' => 'parameter']);
    expect($config->localEnvironment)->toBe(['SHARED' => 'parameter', 'FILE_ONLY' => 'keep', 'PROCESS_ONLY' => 'keep', 'CLEARED' => ''])
        ->and($config->localEnvironmentSources['SHARED'])->toBe('command option')
        ->and($config->localEnvironmentSources['PROCESS_ONLY'])->toBe('AI_HARNESS_LOCAL_ENV')
        ->and(file_get_contents($root.'/.ai-harness.config'))->toBe($contents);
    putenv('AI_HARNESS_ENV=codex-cloud');
    expect((new ConfigLoader)->load($root, ['local_env.SHARED' => 'parameter'])->localEnvironment)->toBe([]);
});

foreach (['[]', 'null', '{"PORT":3307}', '{"lowercase":"value"}', '{broken'] as $value) {
    test('invalid local process JSON is rejected: '.$value, function () use ($value): void {
        putenv('AI_HARNESS_LOCAL_ENV='.$value);
        expect(fn () => (new ConfigLoader)->load(temp_directory('harness-invalid-local-process')))->toThrow(ConfigException::class);
    });
}

test('explicit database parameters take precedence over the process driver', function (): void {
    $root = temp_directory('harness-database-option-precedence');
    file_put_contents($root.'/.env.example', "DB_CONNECTION=sqlite\n");
    putenv('AI_HARNESS_DB_CONNECTION=mysql');
    $loader = new ConfigLoader;
    expect($loader->load($root, ['db_connection' => 'auto'])->existingDatabase)->toBeNull()
        ->and($loader->load($root, ['db_connection' => 'sqlite'])->existingDatabase)->toBeNull()
        ->and($loader->load($root, ['db_connection' => 'mariadb'])->existingDatabase?->connection)->toBe('mariadb');
});
