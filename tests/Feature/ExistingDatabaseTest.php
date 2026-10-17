<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use MrKoopie\LaravelAiHarness\Config\ConfigException;
use MrKoopie\LaravelAiHarness\Config\ConfigLoader;
use MrKoopie\LaravelAiHarness\Config\ExistingDatabase;
use MrKoopie\LaravelAiHarness\Environment\DatabaseName;
use MrKoopie\LaravelAiHarness\Environment\EnvironmentFile;
use MrKoopie\LaravelAiHarness\Environment\ExistingMySql;
use MrKoopie\LaravelAiHarness\Environment\PdoDatabaseConnection;
use MrKoopie\LaravelAiHarness\Environment\StateStore;
use MrKoopie\LaravelAiHarness\Files\SafeWriter;
use Symfony\Component\Process\Process;

test('existing-server environment reconciliation preserves unrelated settings and removes all PHPUnit database overrides', function (): void {
    $root = temp_directory('external-env');
    mkdir($root.'/bootstrap/cache', 0755, true);
    file_put_contents($root.'/bootstrap/cache/config.php', '<?php return [];');
    file_put_contents($root.'/.env', "APP_KEY=existing\nAPP_URL=https://unchanged.test\nDB_CONNECTION=sqlite\nDB_URL=mysql://wrong\nCUSTOM=keep\n");
    file_put_contents($root.'/.env.testing', "APP_ENV=testing\nCACHE_STORE=custom\nCUSTOM=test-keep\nDB_DATABASE=:memory:\nDATABASE_URL=sqlite://wrong\n");
    file_put_contents($root.'/phpunit.xml.dist', <<<'XML'
<phpunit><php><server value="sqlite" force="true" name="DB_CONNECTION"/><env force="true" value=":memory:" name="DB_DATABASE"/><env name="DATABASE_URL" value="sqlite://wrong"/><env name="DB_URL" value="sqlite://wrong"/><env name="DB_HOST" value="wrong"/><env name="CUSTOM" value="keep"/></php></phpunit>
XML);
    $files = new EnvironmentFile(new SafeWriter);
    $config = new ExistingDatabase('127.0.0.1', 3308, '', 'app', 'quote\' dollar$ slash\\ hash#', '%', 'admin', 'never-write-this', 2);
    $files->configureExistingMySql($root, $config);
    $first = file_get_contents($root.'/.env.testing');
    $files->configureExistingMySql($root, $config);
    expect(file_get_contents($root.'/.env.testing'))->toBe($first)
        ->toContain('DB_DATABASE='.DatabaseName::testingForPath($root), 'CACHE_STORE=custom', 'CUSTOM=test-keep', 'DATABASE_URL=', 'DB_PASSWORD="quote\' dollar\\$ slash\\\\ hash#"');
    expect(file_get_contents($root.'/.env.testing'))->not->toContain('never-write-this', ':memory:', 'sqlite://wrong');
    expect(file_get_contents($root.'/.env'))->toContain('APP_URL=https://unchanged.test', 'CUSTOM=keep', 'DB_DATABASE='.DatabaseName::forPath($root));
    expect(file_get_contents($root.'/phpunit.xml'))->toContain('name="CUSTOM"', 'name="APP_ENV" value="testing" force="true"');
    expect($files->existingEnvironmentConflicts($root, $config))->toBe([]);
    expect(file_get_contents($root.'/phpunit.xml'))->not->toContain('DB_', 'DATABASE_URL', 'sqlite');
    expect($root.'/bootstrap/cache/config.php')->not->toBeFile();
});

test('existing-server up and down do not connect to or manage the database server', function (): void {
    $root = temp_directory('external-services');
    file_put_contents($root.'/.ai-harness.config', "runtime=native\n");
    file_put_contents($root.'/.env.example', "DB_CONNECTION=mysql\nDB_USERNAME=app\nDB_HOST=unreachable.invalid\n");
    harness_process(['up'], $root)->mustRun();
    harness_process(['down'], $root)->mustRun();
    expect($root.'/.ai-harness.state.json')->not->toBeFile();
});

test('worktree-specific names cannot override generated database names', function (): void {
    $root = temp_directory('external-conflicts');
    file_put_contents($root.'/.env.example', "DB_CONNECTION=mysql\nDB_USERNAME=app\n");
    file_put_contents($root.'/.ai-harness.config.local', "local_env.DB_DATABASE=secret-must-not-print\n");
    expect(fn () => (new ConfigLoader)->load($root))->toThrow(ConfigException::class, 'local_env.DB_DATABASE is managed');
});

// Opt-in only: CI supplies a disposable MySQL or MariaDB server, never a developer server.
test('existing-server provisioning works against a real MySQL or MariaDB server', function (): void {
    $host = getenv('AI_HARNESS_TEST_DB_HOST');
    if ($host === false || $host === '') {
        $this->markTestSkipped('Set AI_HARNESS_TEST_DB_HOST for a disposable MySQL/MariaDB server.');
    }
    $port = (int) (getenv('AI_HARNESS_TEST_DB_PORT') ?: 3306);
    $password = getenv('AI_HARNESS_TEST_DB_PASSWORD') ?: '';
    $root = temp_directory('external-live');
    $other = temp_directory('external-live');
    $overridden = temp_directory('existing-live-override');
    $customName = 'harness_override_'.substr(hash('sha256', $overridden), 0, 12);
    $user = 'harness_'.substr(hash('sha256', $root), 0, 12);
    $config = new ExistingDatabase((string) $host, $port, '', $user, 'test-app-password', '%', 'root', $password, 2);
    $customConfig = new ExistingDatabase((string) $host, $port, '', $user, 'test-app-password', '%', 'root', $password, 2, database: $customName);
    $admin = new PdoDatabaseConnection($config);
    $admin->execute('CREATE USER ?@? IDENTIFIED BY ?', [$user, '%', 'test-app-password']);
    $manager = new ExistingMySql(new StateStore(new SafeWriter));
    $name = DatabaseName::forPath($root);
    $test = DatabaseName::testingForPath($root);
    try {
        // A prior exact-schema SELECT grant must survive harness cleanup.
        $partial = $admin->rows("SHOW VARIABLES LIKE 'partial_revokes'");
        $literal = strtoupper((string) ($partial[0]['Value'] ?? 'OFF')) === 'ON';
        $grantName = $literal ? $name : str_replace('_', '\\_', $name);
        $admin->execute('GRANT SELECT ON `'.$grantName.'`.* TO ?@?', [$user, '%']);
        mkdir($root.'/vendor');
        file_put_contents($root.'/vendor/autoload.php', '<?php');
        file_put_contents($root.'/.env.example', "APP_KEY=existing\nDB_CONNECTION=mysql\nDB_HOST={$host}\nDB_PORT={$port}\nDB_USERNAME={$user}\nDB_PASSWORD=test-app-password\nDB_DATABASE=shared-original\n");
        file_put_contents($root.'/.ai-harness.config.local', "runtime=native\ndatabase_provision_username=root\ndatabase_provision_password={$password}\ndatabase_parallel_processes=2\n");
        harness_process(['setup'], $root)->mustRun();
        expect(file_get_contents($root.'/.env.testing'))->toContain('DB_DATABASE='.$test);
        expect($manager->diagnose($config)[0])->toBeTrue();
        $app = new PdoDatabaseConnection($config, true);
        $app->execute('CREATE TABLE `'.$name.'`.data (value INT)');
        $app->execute('INSERT INTO `'.$name.'`.data VALUES (42)');
        $manager->setup($root, $config);
        expect($app->rows('SELECT value FROM `'.$name.'`.data')[0]['value'])->toBe(42);
        // Equivalent to Laravel --recreate-databases; schema grants survive dropping a test schema.
        $app->execute('DROP DATABASE `'.$test.'_test_1`');
        $app->execute('CREATE DATABASE `'.$test.'_test_1`');
        $app->execute('CREATE TABLE `'.$test.'_test_1`.parallel_data (value INT)');
        expect(fn () => $app->execute('CREATE DATABASE `'.$test.'_test_3`'))->toThrow(PDOException::class);
        $lookalike = str_replace('_', 'x', $name);
        expect(fn () => $app->execute('CREATE DATABASE `'.$lookalike.'`'))->toThrow(PDOException::class);
        $manager->setup($other, $config);
        harness_process(['cleanup'], $root)->mustRun();
        $manager->cleanup($root, $config);
        expect(fn () => $app->execute('CREATE DATABASE `'.$name.'`'))->toThrow(PDOException::class);
        expect(fn () => $app->execute('CREATE DATABASE `'.$test.'_test_1`'))->toThrow(PDOException::class);
        $prior = $admin->rows('SELECT Select_priv, Create_priv FROM mysql.db WHERE User = ? AND Host = ? AND Db = ?', [$user, '%', $grantName]);
        expect($prior[0]['Select_priv'])->toBe('Y')->and($prior[0]['Create_priv'])->toBe('N');
        expect($admin->rows('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$name]))->toBe([]);
        expect($admin->rows('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$test.'_test_1']))->toBe([]);
        expect($admin->rows('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [DatabaseName::forPath($other)]))->not->toBeEmpty();
        mkdir($overridden.'/vendor');
        file_put_contents($overridden.'/vendor/autoload.php', '<?php');
        file_put_contents($overridden.'/.env.example', "DB_CONNECTION=mysql\nDB_HOST=wrong.invalid\nDB_USERNAME=wrong-user\nDB_PASSWORD=wrong-password\nDB_DATABASE=shared\n");
        copy($root.'/.ai-harness.config.local', $overridden.'/.ai-harness.config.local');
        harness_process(['setup'], $overridden, [
            'AI_HARNESS_DB_HOST' => (string) $host,
            'AI_HARNESS_DB_PORT' => (string) $port,
            'AI_HARNESS_DB_USERNAME' => $user,
            'AI_HARNESS_DB_PASSWORD' => 'test-app-password',
            'AI_HARNESS_DB_DATABASE' => $customName,
        ])->mustRun();
        expect(file_get_contents($overridden.'/.env'))->toContain('DB_DATABASE='.$customName, 'DB_USERNAME='.$user);
        expect(file_get_contents($overridden.'/.env.testing'))->toContain('DB_DATABASE='.$customName.'_testing');
        // A one-command override is persisted safely for subsequent setup and cleanup.
        harness_process(['setup'], $overridden)->mustRun();
        harness_process(['cleanup'], $overridden)->mustRun();
        expect($admin->rows('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$customName]))->toBe([]);

    } finally {
        $manager->cleanup($root, $config);
        $manager->cleanup($other, $config);
        $manager->cleanup($overridden, $customConfig);
        $admin->execute('DROP USER ?@?', [$user, '%']);
    }
});

test('existing-server doctor reports stale environment and PHPUnit settings without editing files', function (): void {
    $root = temp_directory('external-doctor');
    $env = "DB_CONNECTION=sqlite\nDB_DATABASE=:memory:\nDB_URL=mysql://secret\n";
    $xml = '<phpunit><php><server name="DB_CONNECTION" value="sqlite"/></php></phpunit>';
    file_put_contents($root.'/.env', $env);
    file_put_contents($root.'/phpunit.xml', $xml);
    $config = new ExistingDatabase('127.0.0.1', 3306, '', 'app', 'secret', '%', 'admin', 'secret', 0);
    $conflicts = (new EnvironmentFile(new SafeWriter))->existingEnvironmentConflicts($root, $config);
    expect($conflicts)->toContain('.env DB_CONNECTION (run setup)', '.env DB_DATABASE (run setup)', '.env DB_URL (run setup)', 'phpunit.xml inline database overrides (run setup)');
    expect(file_get_contents($root.'/.env'))->toBe($env);
    expect(file_get_contents($root.'/phpunit.xml'))->toBe($xml);
    expect(implode(' ', $conflicts))->not->toContain('secret');
});

test('MariaDB and socket settings from dotenv are retained when databases are renamed', function (): void {
    $root = temp_directory('existing-mariadb');
    file_put_contents($root.'/.env.example', "DB_CONNECTION=mariadb\nDB_USERNAME=app\nDB_PASSWORD=\"literal # password\"\nDB_DATABASE=shared\nDB_SOCKET=/tmp/maria.sock\nMYSQL_ATTR_SSL_CA=/tmp/ca.pem\nAPP_URL=https://unchanged.test\n");
    $files = new EnvironmentFile(new SafeWriter);
    $files->ensure($root);
    $config = (new ConfigLoader)->load($root)->existingDatabase;
    if ($config === null) {
        throw new RuntimeException('Expected MariaDB connection.');
    }
    $files->configureExistingMySql($root, $config);
    foreach (['.env' => DatabaseName::forPath($root), '.env.testing' => DatabaseName::testingForPath($root)] as $file => $database) {
        expect(file_get_contents($root.'/'.$file))->toContain('DB_CONNECTION=mariadb', 'DB_SOCKET=/tmp/maria.sock', 'MYSQL_ATTR_SSL_CA=/tmp/ca.pem', 'DB_PASSWORD="literal # password"', 'DB_DATABASE='.$database);
    }
    expect(file_get_contents($root.'/.env'))->toContain('APP_URL=https://unchanged.test');
});

test('inferred MySQL provisioning still checks unrelated local override conflicts before changes', function (): void {
    $root = temp_directory('existing-redis-conflict');
    file_put_contents($root.'/.env.example', "DB_CONNECTION=mysql\nDB_USERNAME=app\nDB_HOST=unreachable.invalid\nREDIS_URL=redis://private.invalid\n");
    file_put_contents($root.'/.ai-harness.config.local', "local_env.REDIS_HOST=127.0.0.1\n");
    $process = harness_process(['setup'], $root);
    $process->run();
    expect($process->getExitCode())->toBe(1);
    expect($process->getOutput().$process->getErrorOutput())->toContain('REDIS_URL');
    expect($root.'/.env')->not->toBeFile();
});

test('literal process passwords survive dotenv serialization and repeat configuration', function (): void {
    $root = temp_directory('existing-password-literal');
    file_put_contents($root.'/.env', "DB_CONNECTION=mysql\nDB_USERNAME=app\nDB_PASSWORD=file-password\n");
    putenv('AI_HARNESS_DB_PASSWORD=null');
    $config = (new ConfigLoader)->load($root)->existingDatabase;
    if ($config === null) {
        throw new RuntimeException('Expected MySQL connection.');
    }
    (new EnvironmentFile(new SafeWriter))->configureExistingMySql($root, $config);
    putenv('AI_HARNESS_DB_PASSWORD');
    expect(((new ConfigLoader)->load($root)->existingDatabase ?? throw new RuntimeException('Expected existing-server configuration.'))->password)->toBe('null');
    // Laravel checks reserved words before stripping this inner quotation layer.
    $parsed = Dotenv::parse((string) file_get_contents($root.'/.env.testing'));
    expect($parsed['DB_PASSWORD'])->toBe('"null"');
});

test('doctor detects credential and TLS rotations without exposing values', function (): void {
    $root = temp_directory('existing-stale-credentials');
    file_put_contents($root.'/.env', "DB_CONNECTION=mysql\nDB_USERNAME=new-app\nDB_PASSWORD=new-secret\nMYSQL_ATTR_SSL_CA=/new/ca.pem\n");
    $config = (new ConfigLoader)->load($root)->existingDatabase ?? throw new RuntimeException('Expected existing-server configuration.');
    $files = new EnvironmentFile(new SafeWriter);
    $files->configureExistingMySql($root, $config);
    $path = $root.'/.env.testing';
    $contents = str_replace(['new-app', 'new-secret', '/new/ca.pem'], ['old-app', 'old-secret', '/old/ca.pem'], file_get_contents($path) ?: throw new RuntimeException('Missing test environment.'));
    file_put_contents($path, $contents);
    $conflicts = $files->existingEnvironmentConflicts($root, $config);
    expect($conflicts)->toBe(['.env.testing DB_USERNAME (run setup)', '.env.testing DB_PASSWORD (run setup)', '.env.testing MYSQL_ATTR_SSL_CA (run setup)']);
    expect(implode(' ', $conflicts))->not->toContain('secret', 'old-app', 'new-app');
    expect(file_get_contents($path))->toBe($contents);
});

foreach (['dotenv', 'local', 'process'] as $source) {
    test('existing-server credentials with apostrophes and backticks round trip from '.$source, function () use ($source): void {
        $root = temp_directory('existing-special-credentials');
        $password = 'apostrophe\' backtick` dollar$ slash\\ hash#';
        file_put_contents($root.'/.env', "DB_CONNECTION=mysql\nDB_USERNAME=app\nDB_PASSWORD=placeholder\n");
        if ($source === 'dotenv') {
            $encoded = str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $password);
            file_put_contents($root.'/.env', 'DB_CONNECTION=mysql'."\n".'DB_USERNAME=app'."\n".'DB_PASSWORD="'.$encoded.'"'."\n");
        } elseif ($source === 'local') {
            file_put_contents($root.'/.ai-harness.config.local', 'local_env.DB_PASSWORD='.$password."\n");
        } else {
            putenv('AI_HARNESS_DB_PASSWORD='.$password);
        }
        $config = (new ConfigLoader)->load($root)->existingDatabase ?? throw new RuntimeException('Expected existing-server configuration.');
        expect($config->password)->toBe($password);
        $files = new EnvironmentFile(new SafeWriter);
        $files->configureExistingMySql($root, $config);
        putenv('AI_HARNESS_DB_PASSWORD');
        expect(((new ConfigLoader)->load($root)->existingDatabase ?? throw new RuntimeException('Expected existing-server configuration.'))->password)->toBe($password);
        expect($files->existingEnvironmentConflicts($root, $config))->toBe([]);
        expect(Dotenv::parse(file_get_contents($root.'/.env.testing') ?: throw new RuntimeException('Missing test environment.'))['DB_PASSWORD'])->toBe($password);
    });
}

foreach ([false, true] as $existing) {
    test('failed provisioning preserves environment files and cache: '.($existing ? 'existing env' : 'template'), function () use ($existing): void {
        $root = temp_directory('existing-failed-setup');
        mkdir($root.'/bootstrap/cache', 0755, true);
        $snapshots = [
            ($existing ? '.env' : '.env.example') => "DB_CONNECTION=mysql\nDB_HOST=127.0.0.1\nDB_PORT=1\nDB_USERNAME=app\nDB_DATABASE=working\n",
            '.env.testing' => "DB_CONNECTION=sqlite\nDB_DATABASE=:memory:\n",
            'phpunit.xml' => '<phpunit><php><env name="DB_CONNECTION" value="sqlite"/></php></phpunit>',
            'bootstrap/cache/config.php' => '<?php return ["working" => true];',
        ];
        foreach ($snapshots as $path => $contents) {
            file_put_contents($root.'/'.$path, $contents);
        }
        file_put_contents($root.'/.ai-harness.config.local', "runtime=native\nlocal_env.APP_NAME=changed\n");
        $process = harness_process(['setup'], $root);
        $process->run();
        expect($process->getExitCode())->toBe(1);
        expect($process->getOutput().$process->getErrorOutput())->toContain('Existing-server MySQL/MariaDB operation failed');
        foreach ($snapshots as $path => $contents) {
            expect(file_get_contents($root.'/'.$path))->toBe($contents);
        }
        if (! $existing) {
            expect($root.'/.env')->not->toBeFile();
        }
    });
}

test('missing DOM fails before provisioning or environment changes', function (): void {
    $root = temp_directory('existing-no-dom');
    file_put_contents($root.'/.env.example', "DB_CONNECTION=mysql\nDB_HOST=127.0.0.1\nDB_PORT=1\n");
    file_put_contents($root.'/phpunit.xml', '<phpunit/>');
    $script = $root.'/without-dom.php';
    $autoload = var_export(dirname(__DIR__, 2).'/vendor/autoload.php', true);
    $project = var_export($root, true);
    file_put_contents($script, '<?php namespace MrKoopie\\LaravelAiHarness\\Environment { function class_exists(string $name): bool { return $name !== \\DOMDocument::class && \\class_exists($name); } } namespace { require '.$autoload.'; exit((new \\MrKoopie\\LaravelAiHarness\\Application)->run(new \\Symfony\\Component\\Console\\Input\\ArrayInput(["command" => "setup", "--path" => '.$project.']))); }');
    $process = new Process([PHP_BINARY, $script]);
    $process->run();
    expect($process->getExitCode())->toBe(1);
    expect($process->getOutput().$process->getErrorOutput())->toContain('ext-dom');
    expect($root.'/.env')->not->toBeFile();
    expect($root.'/.ai-harness.state.json')->not->toBeFile();
    expect(file_get_contents($root.'/phpunit.xml'))->toBe('<phpunit/>');
});
