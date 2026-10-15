<?php

declare(strict_types=1);

use MrKoopie\LaravelAiHarness\Config\ConfigException;
use MrKoopie\LaravelAiHarness\Config\ConfigLoader;
use MrKoopie\LaravelAiHarness\Environment\Runtime;
use MrKoopie\LaravelAiHarness\Environment\Services;

$savedEnvironment = [];

beforeEach(function () use (&$savedEnvironment): void {
    $savedEnvironment = [];
    foreach (['AI_HARNESS_RUNTIME', 'AI_HARNESS_PHP_VERSION', 'AI_HARNESS_SITE_SECURE', 'AI_HARNESS_SERVICE_MANAGER', 'AI_HARNESS_ENV'] as $name) {
        $savedEnvironment[$name] = getenv($name);
        putenv($name);
    }
});

afterEach(function () use (&$savedEnvironment): void {
    foreach ($savedEnvironment as $name => $value) {
        putenv($value === false ? $name : $name.'='.$value);
    }
});

test('process overrides win over every file layer without changing files', function (): void {
    $root = temp_directory('harness-process-config');
    $contents = "runtime=herd\nphp_version=8.3\nsite_secure=true\nservice_manager=sail\n";
    foreach (['.ai-harness.config.dist', '.ai-harness.config', '.ai-harness.config.global', '.ai-harness.config.local'] as $file) {
        file_put_contents($root.'/'.$file, $contents);
    }
    putenv('AI_HARNESS_RUNTIME=yerd');
    putenv('AI_HARNESS_PHP_VERSION=8.4');
    putenv('AI_HARNESS_SITE_SECURE=off');
    putenv('AI_HARNESS_SERVICE_MANAGER=none');
    $loader = new ConfigLoader;
    $config = $loader->load($root);
    expect($config->runtime)->toBe(Runtime::Yerd)
        ->and($config->phpVersion)->toBe('8.4')
        ->and($config->siteSecure)->toBeFalse()
        ->and($config->serviceManager)->toBe(Services::None);
    foreach (['runtime' => 'RUNTIME', 'php_version' => 'PHP_VERSION', 'site_secure' => 'SITE_SECURE', 'service_manager' => 'SERVICE_MANAGER'] as $key => $suffix) {
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
        ->and($fileConfig->serviceManager)->toBe(Services::Sail)
        ->and($fileConfig->sources['runtime'])->toBe('.ai-harness.config.local');
});

foreach ([
    ['AI_HARNESS_RUNTIME', '', 'runtime must be one of'],
    ['AI_HARNESS_RUNTIME', 'unknown', 'runtime must be one of'],
    ['AI_HARNESS_SERVICE_MANAGER', '', 'service_manager must be one of'],
    ['AI_HARNESS_SERVICE_MANAGER', 'systemd', 'service_manager must be one of'],
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
    file_put_contents($root.'/.ai-harness.config', "service_manager=sail\nsail_services=mysql\n");
    putenv('AI_HARNESS_SERVICE_MANAGER=none');
    expect(fn () => (new ConfigLoader)->load($root))->toThrow(ConfigException::class, 'sail_services may only be set');
});
