<?php

declare(strict_types=1);

use MrKoopie\LaravelAiHarness\Config\ConfigException;
use MrKoopie\LaravelAiHarness\Config\ConfigLoader;
use MrKoopie\LaravelAiHarness\Config\ExistingDatabase;
use MrKoopie\LaravelAiHarness\Environment\DatabaseConnection;
use MrKoopie\LaravelAiHarness\Environment\DatabaseName;
use MrKoopie\LaravelAiHarness\Environment\EnvironmentException;
use MrKoopie\LaravelAiHarness\Environment\ExistingMySql;
use MrKoopie\LaravelAiHarness\Environment\StateStore;
use MrKoopie\LaravelAiHarness\Files\SafeWriter;

/** A stateful server model: inject failures at actual DDL/registry boundaries. */
final class MemoryDatabaseServer implements DatabaseConnection
{
    /** @var array<string, array<string, string>> */
    public array $registry = [];

    /** @var array<string, string> */
    public array $schemas = [];

    /** @var list<string> */
    public array $statements = [];

    /** @var array<string, array<string, string>> */
    public array $grantRegistry = [];

    /** @var array<string, list<string>> */
    public array $schemaPrivileges = [];

    public bool $grantRegistryExists = false;

    public ?string $fail = null;

    public bool $partialRevokes = false;

    /** @var list<list<string>> */
    public array $grantAccounts = [];

    public string $grants = 'GRANT ALL PRIVILEGES ON *.* TO account WITH GRANT OPTION';

    /** Apply server mutations and allow a single injected failure at a chosen boundary. */
    public function execute(string $sql, array $parameters = []): void
    {
        $this->statements[] = $sql;
        if ($this->fail !== null && str_contains($sql, $this->fail)) {
            $this->fail = null;
            throw new RuntimeException('secret password must never appear');
        }
        if (str_contains($sql, 'CREATE TABLE IF NOT EXISTS `ai_harness_registry`.`schema_grants`')) {
            $this->grantRegistryExists = true;
        } elseif (preg_match('/^(GRANT|REVOKE) (.+) ON `([^`]+)`\.\* (?:TO|FROM) /', $sql, $match)) {
            $key = $match[3].'@'.implode('@', $parameters);
            $current = $this->schemaPrivileges[$key] ?? [];
            $privileges = explode(', ', $match[2]);
            $this->schemaPrivileges[$key] = $match[1] === 'GRANT'
                ? array_values(array_unique([...$current, ...$privileges]))
                : array_values(array_diff($current, $privileges));
            if ($match[1] === 'GRANT') {
                $this->grantAccounts[] = $parameters;
            }
        } elseif (str_starts_with($sql, 'INSERT INTO `ai_harness_registry`.`schema_grants`')) {
            $this->grantRegistry[$parameters[0]] = array_combine(['id', 'database_name', 'owner', 'token', 'username', 'user_host', 'grant_schema', 'baseline'], $parameters);
        } elseif (str_starts_with($sql, 'DELETE FROM `ai_harness_registry`.`schema_grants`')) {
            $this->grantRegistry = array_filter($this->grantRegistry, fn ($row) => $row['database_name'] !== $parameters[0]);
        } elseif (str_starts_with($sql, 'INSERT INTO')) {
            $this->registry[$parameters[0]] = ['owner' => $parameters[1], 'token' => $parameters[2], 'created' => '0'];
        } elseif (str_starts_with($sql, 'UPDATE ')) {
            $this->registry[$parameters[0]]['created'] = '1';
        } elseif (str_starts_with($sql, 'DELETE FROM')) {
            unset($this->registry[$parameters[0]]);
        } elseif (preg_match('/^CREATE DATABASE (?:IF NOT EXISTS )?`([^`]+)`/', $sql, $match)) {
            $this->schemas[$match[1]] ??= '';
        } elseif (preg_match('/^DROP DATABASE IF EXISTS `([^`]+)`/', $sql, $match)) {
            unset($this->schemas[$match[1]]);
        }
    }

    /** Return schema, account and registry metadata from the stateful server fixture. */
    public function rows(string $sql, array $parameters = []): array
    {
        $this->statements[] = $sql;
        if (str_contains($sql, 'CURRENT_USER()')) {
            return [['account' => 'app@%']];
        }
        if (str_contains($sql, 'GET_LOCK')) {
            return [['acquired' => '1']];
        }
        if (str_contains($sql, 'SELECT owner, token, created')) {
            return isset($this->registry[$parameters[0]]) ? [$this->registry[$parameters[0]]] : [];
        }
        if (str_contains($sql, 'SELECT * FROM `ai_harness_registry`.`schema_grants`')) {
            return array_values(array_filter($this->grantRegistry, fn ($row) => str_contains($sql, 'WHERE id =') ? $row['id'] === $parameters[0] : $row['database_name'] === $parameters[0]));
        }
        if (str_contains($sql, 'SELECT * FROM mysql.db')) {
            $key = $parameters[2].'@'.$parameters[0].'@'.$parameters[1];
            $flags = ['SELECT' => 'Select_priv', 'CREATE' => 'Create_priv', 'INSERT' => 'Insert_priv', 'UPDATE' => 'Update_priv', 'DELETE' => 'Delete_priv', 'DROP' => 'Drop_priv', 'REFERENCES' => 'References_priv', 'INDEX' => 'Index_priv', 'ALTER' => 'Alter_priv', 'CREATE TEMPORARY TABLES' => 'Create_tmp_table_priv', 'LOCK TABLES' => 'Lock_tables_priv', 'EXECUTE' => 'Execute_priv', 'CREATE VIEW' => 'Create_view_priv', 'SHOW VIEW' => 'Show_view_priv', 'CREATE ROUTINE' => 'Create_routine_priv', 'ALTER ROUTINE' => 'Alter_routine_priv', 'EVENT' => 'Event_priv', 'TRIGGER' => 'Trigger_priv'];
            $row = [];
            foreach ($this->schemaPrivileges[$key] ?? [] as $privilege) {
                $row[$flags[$privilege]] = 'Y';
            }

            return [$row];
        }
        if (str_contains($sql, 'information_schema.TABLES')) {
            return $this->grantRegistryExists ? [['TABLE_NAME' => 'schema_grants']] : [];
        }
        if (str_contains($sql, 'information_schema')) {
            return isset($this->schemas[$parameters[0]]) ? [['SCHEMA_NAME' => $parameters[0]]] : [];
        }
        if (str_contains($sql, 'partial_revokes')) {
            return $this->partialRevokes ? [['Value' => 'ON']] : [];
        }
        if ($sql === 'SHOW GRANTS') {
            return [['Grants' => $this->grants]];
        }

        return [];
    }
}

/** Build application and administrative settings for the in-memory server. */
function existing_config(int $parallel = 0, string $host = '127.0.0.1'): ExistingDatabase
{
    return new ExistingDatabase($host, 3306, '', 'app', 'sensitive', 'localhost', 'admin', 'top-secret', $parallel);
}

/** Route both connection roles through a shared stateful server fixture. */
function existing_manager(MemoryDatabaseServer $server): ExistingMySql
{
    return new ExistingMySql(new StateStore(new SafeWriter), fn () => $server);
}

test('existing-server databases isolate same-named checkouts and repeated setup preserves data', function (): void {
    $one = temp_directory('checkout').'/app';
    $two = temp_directory('checkout').'/app';
    mkdir($one);
    mkdir($two);
    $server = new MemoryDatabaseServer;
    $manager = existing_manager($server);
    $manager->setup($one, existing_config());
    $server->schemas[DatabaseName::forPath($one)] = 'development data';
    $manager->setup($one, existing_config());
    $manager->setup($two, existing_config());
    expect($server->schemas[DatabaseName::forPath($one)])->toBe('development data')
        ->and(count($server->registry))->toBe(4);
    $manager->cleanup($one, existing_config());
    expect($server->schemas)->toHaveKey(DatabaseName::forPath($two));
    expect($server->schemas)->not->toHaveKey(DatabaseName::forPath($one));
    expect($one.'/.ai-harness.state.json')->not->toBeFile();
});

foreach (['CREATE DATABASE `external', 'GRANT '] as $failure) {
    test('existing-server setup recovers after '.$failure, function () use ($failure): void {
        $root = temp_directory('external-partial');
        $server = new MemoryDatabaseServer;
        $server->fail = $failure;
        $manager = existing_manager($server);
        expect(fn () => $manager->setup($root, existing_config()))->toThrow(EnvironmentException::class);
        expect($server->registry)->not->toBeEmpty();
        expect($root.'/.ai-harness.state.json')->toBeFile();
        $manager->setup($root, existing_config());
        expect($server->schemas)->toHaveKeys([DatabaseName::forPath($root), DatabaseName::testingForPath($root)]);
        expect(file_get_contents($root.'/.ai-harness.state.json'))->not->toContain('sensitive', 'top-secret');
    });
}

test('pre-existing databases are never adopted even with a local intent record', function (): void {
    $root = temp_directory('external-existing');
    $server = new MemoryDatabaseServer;
    $name = DatabaseName::forPath($root);
    $server->schemas[$name] = 'unrelated';
    $manager = existing_manager($server);
    for ($i = 0; $i < 2; $i++) {
        expect(fn () => $manager->setup($root, existing_config()))->toThrow(EnvironmentException::class, 'without matching ownership');
    }
    expect($server->registry)->toBeEmpty();
    expect(fn () => $manager->cleanup($root, existing_config()))->toThrow(EnvironmentException::class);
    expect($server->schemas[$name])->toBe('unrelated');
});

foreach (['copy', 'endpoint', 'registry'] as $tamper) {
    test('ownership mismatch cannot authorize cleanup: '.$tamper, function () use ($tamper): void {
        $root = temp_directory('external-owner');
        $server = new MemoryDatabaseServer;
        $manager = existing_manager($server);
        $manager->setup($root, existing_config());
        $target = $root;
        $config = existing_config();
        if ($tamper === 'copy') {
            $target = temp_directory('external-copy');
            copy($root.'/.ai-harness.state.json', $target.'/.ai-harness.state.json');
        } elseif ($tamper === 'endpoint') {
            $config = existing_config(host: 'another-server');
        } else {
            $server->registry[DatabaseName::forPath($root)]['token'] = str_repeat('0', 64);
        }
        expect(fn () => $manager->cleanup($target, $config))->toThrow(EnvironmentException::class);
        expect($server->schemas)->toHaveKey(DatabaseName::forPath($root));
    });
}

foreach (['DROP DATABASE', 'DELETE FROM'] as $failure) {
    test('cleanup retries retain evidence after '.$failure, function () use ($failure): void {
        $root = temp_directory('external-retry');
        $server = new MemoryDatabaseServer;
        $manager = existing_manager($server);
        $manager->setup($root, existing_config());
        $server->fail = $failure;
        expect(fn () => $manager->cleanup($root, existing_config()))->toThrow(EnvironmentException::class);
        expect($root.'/.ai-harness.state.json')->toBeFile();
        $manager->cleanup($root, existing_config());
        expect($server->registry)->toBeEmpty();
        expect($root.'/.ai-harness.state.json')->not->toBeFile();
    });
}

foreach ([false, true] as $partialRevokes) {
    test('parallel grants only cover reserved databases with partial_revokes='.(int) $partialRevokes, function () use ($partialRevokes): void {
        $root = temp_directory('external-parallel');
        $server = new MemoryDatabaseServer;
        $server->partialRevokes = $partialRevokes;
        $manager = existing_manager($server);
        $manager->setup($root, existing_config(2));
        $base = DatabaseName::testingForPath($root);
        $server->schemas[$base.'_test_3'] = 'unowned';
        expect($server->schemas)->toHaveKeys([$base.'_test_1', $base.'_test_2']);
        $grants = array_values(array_filter($server->statements, fn ($sql) => str_starts_with($sql, 'GRANT')));
        expect($grants[0])->toContain($partialRevokes ? DatabaseName::forPath($root) : str_replace('_', '\\_', DatabaseName::forPath($root)));
        // A lower configured count must not lose ownership of previous processes.
        $manager->setup($root, existing_config());
        $manager->cleanup($root, existing_config());
        expect($server->schemas)->toHaveKey($base.'_test_3');
        expect($server->schemas)->not->toHaveKey($base.'_test_1');
    });
}

test('dotenv connection and local overrides select provisioning while Sail keeps precedence', function (): void {
    $root = temp_directory('external-config');
    file_put_contents($root.'/.env.example', "DB_CONNECTION=mysql\nDB_USERNAME=app\nDB_PORT=3307\n");
    file_put_contents($root.'/.ai-harness.config.local', "local_env.DB_PASSWORD=private\nlocal_env.DB_PORT=3308\n");
    $config = (new ConfigLoader)->load($root);
    expect($config->existingDatabase?->port)->toBe(3308)
        ->and($config->existingDatabase?->provisionPassword)->toBe('private')
        ->and($config->managesMySql())->toBeFalse();
    file_put_contents($root.'/.ai-harness.config.local', "sail_services=mysql\n");
    $sail = (new ConfigLoader)->load($root);
    expect($sail->existingDatabase)->toBeNull()->and($sail->managesMySql())->toBeTrue();
});

test('doctor only reads server metadata and redacts connection failures', function (): void {
    $server = new MemoryDatabaseServer;
    $manager = existing_manager($server);
    expect($manager->diagnose(existing_config())[0])->toBeTrue();
    expect(implode("\n", $server->statements))->not->toContain('CREATE', 'DROP', 'INSERT', 'DELETE');
    $failed = new ExistingMySql(new StateStore(new SafeWriter), function () {
        throw new RuntimeException('password-secret');
    });
    expect($failed->diagnose(existing_config()))->toBe([false, 'Existing-server MySQL/MariaDB connection or privilege inspection failed; check credentials, endpoint and pdo_mysql']);
});

test('doctor identifies missing global provisioning and grant privileges', function (): void {
    $server = new MemoryDatabaseServer;
    $server->grants = 'GRANT SELECT, INSERT ON *.* TO account';
    [$ok, $message] = existing_manager($server)->diagnose(existing_config());
    expect($ok)->toBeFalse();
    expect($message)->toContain('DROP', 'GRANT OPTION');
});

test('a collision after reservation or an ambiguous create acknowledgement never authorizes adoption or deletion', function (): void {
    $root = temp_directory('external-ambiguous');
    $server = new MemoryDatabaseServer;
    $server->fail = 'UPDATE ';
    $manager = existing_manager($server);
    expect(fn () => $manager->setup($root, existing_config()))->toThrow(EnvironmentException::class);
    expect(fn () => $manager->setup($root, existing_config()))->toThrow(EnvironmentException::class, 'ownership is unverified');
    expect(fn () => $manager->cleanup($root, existing_config()))->toThrow(EnvironmentException::class, 'ownership is unverified');
    expect($server->schemas)->toHaveKey(DatabaseName::forPath($root));
    expect($root.'/.ai-harness.state.json')->toBeFile();
});

test('existing-server credentials inherit from the verified primary checkout and local overrides win', function (): void {
    $primary = temp_directory('external-primary');
    $root = temp_directory('external-linked');
    $metadata = $primary.'/.git/worktrees/linked';
    mkdir($metadata, 0755, true);
    file_put_contents($root.'/.git', 'gitdir: '.$metadata);
    file_put_contents($metadata.'/commondir', '../..');
    file_put_contents($metadata.'/gitdir', $root.'/.git');
    file_put_contents($primary.'/.ai-harness.config.local', "local_env.DB_CONNECTION=mysql\nlocal_env.DB_USERNAME=app\nlocal_env.DB_PASSWORD=inherited\nlocal_env.DB_PORT=3307\n");
    file_put_contents($root.'/.ai-harness.config.local', "local_env.DB_PORT=3308\n");
    $config = (new ConfigLoader)->load($root);
    expect($config->existingDatabase?->password)->toBe('inherited')
        ->and($config->existingDatabase?->port)->toBe(3308);
});

test('cloud configuration leaves existing-server provisioning inactive', function (): void {
    $root = temp_directory('external-cloud');
    file_put_contents($root.'/.env.example', "DB_CONNECTION=mysql\nDB_USERNAME=app\n");
    $previous = getenv('AI_HARNESS_ENV');
    putenv('AI_HARNESS_ENV=codex-cloud');
    try {
        $config = (new ConfigLoader)->load($root);
        expect($config->existingDatabase)->toBeNull()
            ->and($config->cloudServices)->toBe(['mysql']);
    } finally {
        putenv($previous === false ? 'AI_HARNESS_ENV' : 'AI_HARNESS_ENV='.$previous);
    }
});

test('cleanup of an uncreated reservation never sends DROP DATABASE', function (): void {
    $root = temp_directory('external-reservation');
    $server = new MemoryDatabaseServer;
    $server->fail = 'CREATE DATABASE `external';
    $manager = existing_manager($server);
    expect(fn () => $manager->setup($root, existing_config()))->toThrow(EnvironmentException::class);
    $server->statements = [];
    $manager->cleanup($root, existing_config());
    expect(implode("\n", $server->statements))->not->toContain('DROP DATABASE');
    expect($server->registry)->toBeEmpty();
    expect($root.'/.ai-harness.state.json')->not->toBeFile();
});

test('provisioning discovers the actual application account host and cleanup only needs administrator access', function (): void {
    $root = temp_directory('existing-account');
    file_put_contents($root.'/.env.example', "DB_CONNECTION=mysql\nDB_USERNAME=app\n");
    file_put_contents($root.'/.ai-harness.config.local', "database_provision_username=admin\n");
    $config = (new ConfigLoader)->load($root)->existingDatabase;
    if ($config === null) {
        throw new RuntimeException('Expected MySQL connection.');
    }
    $server = new MemoryDatabaseServer;
    $manager = existing_manager($server);
    $manager->setup($root, $config);
    expect($server->grantAccounts)->toBe([['app', '%'], ['app', '%']]);
    $cleanup = new ExistingMySql(new StateStore(new SafeWriter), function ($settings, bool $application) use ($server) {
        if ($application) {
            throw new RuntimeException('Application account has been disabled.');
        }

        return $server;
    });
    $cleanup->cleanup($root, $config);
    expect($server->registry)->toBeEmpty();
});

test('overridden names retain ownership and remain isolated across checkout collisions', function (): void {
    $one = temp_directory('existing-custom');
    $two = temp_directory('existing-custom');
    foreach ([$one, $two] as $root) {
        file_put_contents($root.'/.env', "DB_CONNECTION=mysql\nDB_USERNAME=app\n");
    }
    putenv('AI_HARNESS_DB_DATABASE=custom_database');
    $config = (new ConfigLoader)->load($one)->existingDatabase;
    if ($config === null) {
        throw new RuntimeException('Expected MySQL connection.');
    }
    $server = new MemoryDatabaseServer;
    $manager = existing_manager($server);
    $manager->setup($one, $config);
    $server->schemas['custom_database'] = 'preserved data';
    expect(fn () => $manager->setup($two, $config))->toThrow(EnvironmentException::class, 'ownership does not match');
    expect(fn () => $manager->cleanup($two, $config))->toThrow(EnvironmentException::class, 'ownership does not match');
    putenv('AI_HARNESS_DB_DATABASE');
    $resumed = (new ConfigLoader)->load($one)->existingDatabase;
    expect($resumed->developmentName($one))->toBe('custom_database');
    $manager->setup($one, $resumed);
    expect($server->schemas['custom_database'])->toBe('preserved data');
    $manager->cleanup($one, $resumed);
    expect($server->schemas)->not->toHaveKey('custom_database');
});

test('cleanup revokes only grants added by this checkout and preserves prior access', function (): void {
    $root = temp_directory('existing-grant-baseline');
    $server = new MemoryDatabaseServer;
    $schema = str_replace('_', '\\_', DatabaseName::forPath($root));
    $key = $schema.'@app@localhost';
    $server->schemaPrivileges[$key] = ['SELECT'];
    $server->schemaPrivileges['unrelated@other@%'] = ['CREATE'];
    $manager = existing_manager($server);
    $manager->setup($root, existing_config(2));
    $manager->setup($root, existing_config(2));
    $manager->cleanup($root, existing_config());
    expect($server->schemaPrivileges[$key])->toBe(['SELECT'])
        ->and($server->schemaPrivileges['unrelated@other@%'])->toBe(['CREATE'])
        ->and($server->grantRegistry)->toBeEmpty();
    foreach ($server->schemaPrivileges as $account => $privileges) {
        if (! in_array($account, [$key, 'unrelated@other@%'], true)) {
            expect($privileges)->toBe([]);
        }
    }
});

test('cleanup retains grant evidence on revocation failure and retries without dropping early', function (): void {
    $root = temp_directory('existing-revoke-retry');
    $server = new MemoryDatabaseServer;
    $manager = existing_manager($server);
    $manager->setup($root, existing_config());
    $server->fail = 'REVOKE ';
    expect(fn () => $manager->cleanup($root, existing_config()))->toThrow(EnvironmentException::class);
    expect($server->schemas)->toHaveKey(DatabaseName::forPath($root));
    expect($server->grantRegistry)->not->toBeEmpty();
    expect($root.'/.ai-harness.state.json')->toBeFile();
    $manager->cleanup($root, existing_config());
    expect($server->grantRegistry)->toBeEmpty();
    expect(array_keys($server->schemas))->toBe(['ai_harness_registry']);
});

test('cleanup revokes all recorded account hosts after application credential rotation', function (): void {
    $root = temp_directory('existing-grant-rotation');
    $server = new MemoryDatabaseServer;
    $manager = existing_manager($server);
    $manager->setup($root, existing_config());
    $rotated = new ExistingDatabase('127.0.0.1', 3306, '', 'replacement', '', '%', 'admin', '', 0);
    $manager->setup($root, $rotated);
    expect($server->grantRegistry)->toHaveCount(4);
    $manager->cleanup($root, $rotated);
    expect(array_filter($server->schemaPrivileges))->toBe([]);
});

test('tampered grant ownership cannot revoke permissions or drop databases', function (): void {
    $root = temp_directory('existing-grant-tamper');
    $server = new MemoryDatabaseServer;
    $manager = existing_manager($server);
    $manager->setup($root, existing_config());
    $id = array_key_first($server->grantRegistry);
    $server->grantRegistry[$id]['token'] = str_repeat('0', 64);
    expect(fn () => $manager->cleanup($root, existing_config()))->toThrow(EnvironmentException::class, 'grant ownership');
    expect($server->schemas)->toHaveKey(DatabaseName::forPath($root));
    expect(array_filter($server->statements, fn ($sql) => str_starts_with($sql, 'REVOKE')))->toBe([]);
});

test('socket ownership ignores unused TCP settings and migrates matching legacy records', function (): void {
    $root = temp_directory('existing-socket-identity');
    $server = new MemoryDatabaseServer;
    $manager = existing_manager($server);
    $original = new ExistingDatabase('old-host', 3306, '/tmp/test.sock', 'app', '', '%', 'admin', '', 0);
    $changed = new ExistingDatabase('new-host', 3307, '/tmp/test.sock', 'app', '', '%', 'admin', '', 0);
    $different = new ExistingDatabase('old-host', 3306, '/tmp/other.sock', 'app', '', '%', 'admin', '', 0);
    expect($original->endpoint())->toBe($changed->endpoint());
    expect($original->endpoint())->not->toBe($different->endpoint());
    $manager->setup($root, $original);
    $path = $root.'/.ai-harness.state.json';
    $state = json_decode(file_get_contents($path) ?: throw new RuntimeException('Missing ownership state.'), true);
    $state['existing_databases']['endpoint'] = $original->legacyEndpoint();
    file_put_contents($path, json_encode($state));
    $manager->setup($root, $original);
    $manager->setup($root, $changed);
    expect(fn () => $manager->cleanup($root, $different))->toThrow(EnvironmentException::class);
    $manager->cleanup($root, $changed);
    expect(array_keys($server->schemas))->toBe(['ai_harness_registry']);
});

foreach (['', 'mysql-typo', 'pgsql'] as $driver) {
    test('an explicit unsupported provisioning driver fails clearly: '.($driver ?: 'empty'), function () use ($driver): void {
        $root = temp_directory('existing-invalid-driver');
        file_put_contents($root.'/.env.example', "DB_CONNECTION=mysql\n");
        putenv('AI_HARNESS_DB_CONNECTION='.$driver);
        expect(fn () => (new ConfigLoader)->load($root))->toThrow(ConfigException::class, 'db_connection must be');
        putenv('AI_HARNESS_DB_CONNECTION');
        file_put_contents($root.'/.env.example', 'DB_CONNECTION='.$driver."\n");
        if (! in_array($driver, ['mysql-typo'], true)) {
            expect((new ConfigLoader)->load($root)->existingDatabase)->toBeNull();
        }
    });
}

test('a failed first grant retains the original privilege baseline for setup and cleanup retries', function (): void {
    $root = temp_directory('existing-grant-partial');
    $server = new MemoryDatabaseServer;
    $key = str_replace('_', '\\_', DatabaseName::forPath($root)).'@app@localhost';
    $server->schemaPrivileges[$key] = ['SELECT'];
    $server->fail = 'GRANT ';
    $manager = existing_manager($server);
    expect(fn () => $manager->setup($root, existing_config()))->toThrow(EnvironmentException::class);
    expect($server->grantRegistry)->toHaveCount(1);
    $manager->setup($root, existing_config());
    $manager->cleanup($root, existing_config());
    expect($server->schemaPrivileges[$key])->toBe(['SELECT']);
});

test('cleanup refuses changed server grant semantics while retaining ownership for recovery', function (): void {
    $root = temp_directory('existing-grant-mode');
    $server = new MemoryDatabaseServer;
    $manager = existing_manager($server);
    $manager->setup($root, existing_config());
    $server->partialRevokes = true;
    expect(fn () => $manager->cleanup($root, existing_config()))->toThrow(EnvironmentException::class, 'grant mode changed');
    expect($server->schemas)->toHaveKey(DatabaseName::forPath($root));
    expect($root.'/.ai-harness.state.json')->toBeFile();
    $server->partialRevokes = false;
    $manager->cleanup($root, existing_config());
    expect($server->grantRegistry)->toBeEmpty();
});

test('existing-server local overrides retain shell restrictions when Sail manages other services', function (): void {
    $root = temp_directory('existing-sail-redis-credential');
    file_put_contents($root.'/.env.example', "DB_CONNECTION=mysql\nDB_USERNAME=app\n");
    file_put_contents($root.'/.ai-harness.config.local', "runtime=native\nsail_services=redis\nlocal_env.DB_PASSWORD=apostrophe'backtick`\n");
    expect(fn () => (new ConfigLoader)->load($root))->toThrow(ConfigException::class, 'cannot combine backticks');
});
