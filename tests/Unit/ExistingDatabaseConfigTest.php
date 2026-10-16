<?php

declare(strict_types=1);

use MrKoopie\LaravelAiHarness\Config\ConfigException;
use MrKoopie\LaravelAiHarness\Config\ConfigLoader;
use MrKoopie\LaravelAiHarness\Environment\DatabaseName;
use PHPUnit\Framework\Assert;

foreach (['native', 'herd', 'valet', 'yerd'] as $runtime) {
    test('MySQL settings in dotenv select existing-server provisioning for '.$runtime, function () use ($runtime): void {
        $root = temp_directory('existing-inferred');
        file_put_contents($root.'/.ai-harness.config', 'runtime='.$runtime);
        file_put_contents($root.'/.env.example', "DB_CONNECTION=mysql\nDB_USERNAME=example-user\n");
        file_put_contents($root.'/.env', "DB_CONNECTION=mysql\nDB_USERNAME=actual-user\nDB_PASSWORD=\nDB_DATABASE=shared-name\n");
        $config = (new ConfigLoader)->load($root);
        expect($config->existingDatabase?->username)->toBe('actual-user')
            ->and($config->existingDatabase?->host)->toBe('127.0.0.1')
            ->and($config->existingDatabase?->port)->toBe(3306)
            ->and($config->existingDatabase?->password)->toBe('')
            ->and($config->existingDatabase?->userHost)->toBe('')
            ->and($config->existingDatabase?->provisionUsername)->toBe('actual-user');
        expect(DatabaseName::forPath($root))->not->toBe('shared-name');
        expect(file_get_contents($root.'/.env'))->toContain('DB_DATABASE=shared-name');
    });
}

test('dotenv reads quoting, comments, escapes and variable references without mutating process settings', function (): void {
    $root = temp_directory('existing-dotenv');
    file_put_contents($root.'/.env.example', <<<'ENV'
BASE_USER='app user'
PASSWORD_PART='literal # dollar$'
export DB_CONNECTION = "mariadb"
DB_HOST=127.0.0.1 # local server
DB_PORT="3307"
DB_USERNAME="${BASE_USER}"
DB_PASSWORD="${PASSWORD_PART} \"quote\" \\ slash"
DB_SOCKET=/tmp/example.sock
MYSQL_ATTR_SSL_CA=/tmp/example-ca.pem
ENV);
    $before = getenv('DB_USERNAME');
    $config = (new ConfigLoader)->load($root)->existingDatabase;
    expect($config?->connection)->toBe('mariadb')
        ->and($config?->host)->toBe('127.0.0.1')
        ->and($config?->port)->toBe(3307)
        ->and($config?->username)->toBe('app user')
        ->and($config?->password)->toBe('literal # dollar$ "quote" \\ slash')
        ->and($config?->socket)->toBe('/tmp/example.sock')
        ->and($config?->sslCa)->toBe('/tmp/example-ca.pem')
        ->and(getenv('DB_USERNAME'))->toBe($before);
    expect($root.'/.env')->not->toBeFile();
});

test('personal credentials override dotenv and separate provisioning credentials remain private', function (): void {
    $root = temp_directory('existing-separate');
    file_put_contents($root.'/.env.example', "DB_CONNECTION=mysql\nDB_USERNAME=app\nDB_PASSWORD=example\n");
    file_put_contents($root.'/.ai-harness.config.dist', "database_parallel_processes=2\n");
    file_put_contents($root.'/.ai-harness.config.local', "local_env.DB_PASSWORD=personal\nlocal_env.DB_PORT=3310\ndatabase_provision_username=admin\ndatabase_provision_password=admin-secret\n");
    $config = (new ConfigLoader)->load($root)->existingDatabase;
    expect($config?->password)->toBe('personal')
        ->and($config?->port)->toBe(3310)
        ->and($config?->provisionUsername)->toBe('admin')
        ->and($config?->provisionPassword)->toBe('admin-secret')
        ->and($config?->parallelProcesses)->toBe(2);
    expect(file_get_contents($root.'/.env.example'))->not->toContain('personal', 'admin-secret');
});

foreach (['sqlite', 'pgsql', ''] as $driver) {
    test('non-MySQL dotenv preserves existing behavior: '.$driver, function () use ($driver): void {
        $root = temp_directory('existing-other');
        file_put_contents($root.'/.ai-harness.config', 'runtime=yerd');
        file_put_contents($root.'/.env.example', 'DB_CONNECTION='.$driver);
        expect((new ConfigLoader)->load($root)->existingDatabase)->toBeNull();
    });
}

test('dotenv shell expressions are literal data', function (): void {
    $root = temp_directory('existing-data');
    file_put_contents($root.'/.env', "DB_CONNECTION=mysql\nDB_USERNAME=app\nDB_PASSWORD='\$(touch {$root}/executed)'\n");
    expect((new ConfigLoader)->load($root)->existingDatabase?->password)->toBe('$(touch '.$root.'/executed)');
    expect($root.'/executed')->not->toBeFile();
});

test('invalid dotenv errors do not disclose the offending credential', function (): void {
    $root = temp_directory('existing-invalid');
    file_put_contents($root.'/.env', "DB_CONNECTION=mysql\nDB_PASSWORD=secret unquoted spaces\n");
    try {
        (new ConfigLoader)->load($root);
        Assert::fail('Expected invalid dotenv to be rejected.');
    } catch (ConfigException $exception) {
        expect($exception->getMessage())->toContain('dotenv syntax');
        expect($exception->getMessage())->not->toContain('secret unquoted spaces');
    }
});

foreach (["runtime=sail\nservice_manager=sail\n", "runtime=herd\nservice_manager=sail\n", "runtime=valet\nservice_manager=sail\nsail_services=mysql\n"] as $sail) {
    test('Sail database behavior has precedence over inferred host provisioning: '.$sail, function () use ($sail): void {
        $root = temp_directory('existing-sail');
        file_put_contents($root.'/.ai-harness.config', $sail);
        file_put_contents($root.'/.env.example', "DB_CONNECTION=mysql\nDB_HOST=mysql\nDB_USERNAME=sail\nDB_PASSWORD=password\n");
        expect((new ConfigLoader)->load($root)->existingDatabase)->toBeNull();
    });
}

test('prefixed process overrides win over dotenv and personal settings without changing the source files', function (): void {
    $root = temp_directory('existing-process');
    $contents = "DB_CONNECTION=mysql\nDB_USERNAME=file-user\nDB_PASSWORD=file-password\nDB_HOST=file.invalid\nDB_PORT=3306\nDB_DATABASE=shared\nDB_SOCKET=/file.sock\n";
    file_put_contents($root.'/.env', $contents);
    file_put_contents($root.'/.ai-harness.config.local', "local_env.DB_USERNAME=local-user\nlocal_env.DB_PASSWORD=local-password\nlocal_env.DB_HOST=local.invalid\n");
    foreach (['USERNAME' => 'process-user', 'PASSWORD' => ' password with spaces ', 'HOST' => 'process.invalid', 'PORT' => '3311', 'SOCKET' => '', 'DATABASE' => 'chosen_database'] as $suffix => $value) {
        putenv('AI_HARNESS_DB_'.$suffix.'='.$value);
    }
    $config = (new ConfigLoader)->load($root)->existingDatabase;
    expect($config?->username)->toBe('process-user')
        ->and($config?->password)->toBe(' password with spaces ')
        ->and($config?->provisionPassword)->toBe(' password with spaces ')
        ->and($config?->host)->toBe('process.invalid')
        ->and($config?->port)->toBe(3311)
        ->and($config?->socket)->toBe('')
        ->and($config?->developmentName($root))->toBe('chosen_database')
        ->and($config?->testingName($root))->toBe('chosen_database_testing');
    expect(file_get_contents($root.'/.env'))->toBe($contents);
    expect($config?->environmentOverrides)->toContain('AI_HARNESS_DB_PASSWORD', 'AI_HARNESS_DB_DATABASE');
});

test('empty process password is deliberate and connection override can select MySQL', function (): void {
    $root = temp_directory('existing-process-empty');
    file_put_contents($root.'/.env', "DB_CONNECTION=sqlite\nDB_USERNAME=app\nDB_PASSWORD=file-secret\n");
    putenv('AI_HARNESS_DB_CONNECTION=mysql');
    putenv('AI_HARNESS_DB_PASSWORD=');
    expect((new ConfigLoader)->load($root)->existingDatabase?->password)->toBe('');
    putenv('AI_HARNESS_DB_PASSWORD=null');
    expect((new ConfigLoader)->load($root)->existingDatabase?->password)->toBe('null');
});

foreach (['unsafe;DROP_DATABASE', '../other', 'ai_harness_registry', str_repeat('a', 49)] as $name) {
    test('unsafe or overlong database override is rejected: '.substr($name, 0, 20), function () use ($name): void {
        $root = temp_directory('existing-process-invalid');
        file_put_contents($root.'/.env', "DB_CONNECTION=mysql\n");
        putenv('AI_HARNESS_DB_DATABASE='.$name);
        expect(fn () => (new ConfigLoader)->load($root))->toThrow(ConfigException::class, 'AI_HARNESS_DB_DATABASE');
    });
}

test('maximum database override leaves room for testing and parallel tokens', function (): void {
    $root = temp_directory('existing-process-length');
    file_put_contents($root.'/.env', "DB_CONNECTION=mysql\n");
    putenv('AI_HARNESS_DB_DATABASE='.str_repeat('a', 48));
    $config = (new ConfigLoader)->load($root)->existingDatabase;
    expect(strlen(($config?->testingName($root) ?? '').'_test_64'))->toBe(64);
});
