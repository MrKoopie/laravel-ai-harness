<?php

declare(strict_types=1);

use MrKoopie\LaravelAiHarness\Environment\DatabaseName;
use MrKoopie\LaravelAiHarness\Environment\EnvironmentException;
use MrKoopie\LaravelAiHarness\Environment\EnvironmentFile;
use MrKoopie\LaravelAiHarness\Environment\HostMySql;
use MrKoopie\LaravelAiHarness\Files\SafeWriter;

// PDO boundary fake: tests exercise real ownership, dotenv parsing, and SQL selection.
class HarnessMySqlConnection extends PDO
{
    /** @var list<string> */
    public array $statements = [];

    /** @var list<string> */
    public array $databases = [];

    public bool $fail = false;

    public function __construct() {}

    public function exec(string $statement): int|false
    {
        if ($this->fail) {
            throw new PDOException('fixture failure');
        }
        $this->statements[] = $statement;

        return 0;
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        return new HarnessMySqlStatement($this->databases);
    }
}
class HarnessMySqlStatement extends PDOStatement
{
    /** @param list<string> $databases */
    public function __construct(private array $databases) {}

    /** @return list<string> */
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return $this->databases;
    }
}

/** @return array{string, HarnessMySqlConnection, HostMySql} */
function host_mysql_fixture(): array
{
    $root = temp_directory('harness-host-mysql');
    file_put_contents($root.'/.env', "APP_KEY=existing\nDB_CONNECTION=sqlite\nDB_HOST=127.0.0.1\nDB_PORT=3307\nDB_USERNAME=developer\nDB_PASSWORD=\"password with spaces\"\nDB_DATABASE=real_app\n");
    $pdo = new HarnessMySqlConnection;

    return [$root, $pdo, new HostMySql(fn () => $pdo)];
}

test('host MySQL setup and cleanup remove only owned databases and exact numeric workers', function (): void {
    [$root, $pdo, $mysql] = host_mysql_fixture();
    $mysql->run($root, 'setup');
    $database = DatabaseName::forPath($root);
    $testing = DatabaseName::testingForPath($root);
    expect(file_get_contents($root.'/.env'))->toContain('DB_DATABASE='.$database)
        ->and(file_get_contents($root.'/.env.testing'))->toContain('DB_DATABASE='.$testing)
        ->and(file_get_contents($root.'/.ai-harness.state.json'))->not->toContain('password with spaces');
    $pdo->databases = [$testing.'_1', $testing.'_test_2', $testing.'_test_2_backup', $testing.'_other', 'other_testing_test_1'];
    $pdo->statements = [];
    $mysql->run($root, 'cleanup');
    expect($pdo->statements)->toBe([
        'DROP DATABASE IF EXISTS `'.$testing.'_1`',
        'DROP DATABASE IF EXISTS `'.$testing.'_test_2`',
        'DROP DATABASE IF EXISTS `'.$testing.'`',
        'DROP DATABASE IF EXISTS `'.$database.'`',
    ])->and($root.'/.ai-harness.state.json')->not->toBeFile();
    $mysql->run($root, 'cleanup');
    expect($pdo->statements)->toHaveCount(4);
});

test('host cleanup retains ownership on SQL failure and can be retried after settings change', function (): void {
    [$root, $pdo, $mysql] = host_mysql_fixture();
    $mysql->run($root, 'setup');
    $pdo->fail = true;
    try {
        $mysql->run($root, 'cleanup');
    } catch (PDOException) {
    }
    expect($root.'/.ai-harness.state.json')->toBeFile();
    $pdo->fail = false;
    $mysql->run($root, 'cleanup');
    expect($root.'/.ai-harness.state.json')->not->toBeFile();
});

test('host cleanup rejects changed endpoints and copied ownership without issuing SQL', function (): void {
    [$root, $pdo, $mysql] = host_mysql_fixture();
    $mysql->run($root, 'setup');
    $pdo->statements = [];
    file_put_contents($root.'/.env', str_replace('3307', '3308', (string) file_get_contents($root.'/.env')));
    expect(fn () => $mysql->run($root, 'cleanup'))->toThrow(EnvironmentException::class)
        ->and($pdo->statements)->toBe([]);
    $copy = temp_directory('harness-copied-mysql');
    copy($root.'/.env', $copy.'/.env');
    copy($root.'/.ai-harness.state.json', $copy.'/.ai-harness.state.json');
    expect(fn () => $mysql->run($copy, 'cleanup'))->toThrow(EnvironmentException::class)
        ->and($pdo->statements)->toBe([]);
});

test('partial host creation keeps ownership and refuses to adopt existing unowned databases', function (): void {
    [$root, $pdo, $mysql] = host_mysql_fixture();
    $pdo->databases = [DatabaseName::forPath($root)];
    expect(fn () => $mysql->run($root, 'setup'))->toThrow(EnvironmentException::class)
        ->and($root.'/.ai-harness.state.json')->not->toBeFile()
        ->and($pdo->statements)->toBe([]);
    $pdo->databases = [];
    $pdo->fail = true;
    expect(fn () => $mysql->run($root, 'setup'))->toThrow(PDOException::class)
        ->and($root.'/.ai-harness.state.json')->toBeFile();
    $pdo->fail = false;
    $mysql->run($root, 'setup');
    $mysql->run($root, 'cleanup');
    expect($root.'/.ai-harness.state.json')->not->toBeFile();
});

test('host MySQL reconciles forced PHPUnit endpoints with literal credentials', function (): void {
    [$root, $pdo, $mysql] = host_mysql_fixture();
    $password = 'a "quoted" ${PATH} \\ value';
    $file = new EnvironmentFile(new SafeWriter);
    $file->applyLocalOverrides($root, ['DB_PASSWORD' => $password]);
    file_put_contents($root.'/phpunit.xml', '<phpunit><php><env name="DB_DATABASE" value="real_app" force="true"/><env name="DB_PASSWORD" value="old" force="true"/><env name="APP_KEY" value="old" force="true"/><env name="APP_CONFIG_CACHE" value="/outside.php" force="true"/></php></phpunit>');
    $mysql->run($root, 'setup');
    $values = Dotenv\Dotenv::createArrayBacked($root, '.env.testing')->load();
    $document = new DOMDocument;
    $document->load($root.'/phpunit.xml');
    $xpath = new DOMXPath($document);
    expect($values['DB_PASSWORD'])->toBe($password)
        ->and($xpath->evaluate('string(/phpunit/php/env[@name="DB_PASSWORD"]/@value)'))->toBe($password)
        ->and($xpath->evaluate('string(/phpunit/php/env[@name="DB_DATABASE"]/@value)'))->toBe(DatabaseName::testingForPath($root))
        ->and($xpath->evaluate('string(/phpunit/php/env[@name="APP_KEY"]/@value)'))->toBe('existing')
        ->and($xpath->evaluate('string(/phpunit/php/env[@name="APP_CONFIG_CACHE"]/@value)'))->toBe('bootstrap/cache/config.php');
});

test('cleanup finds host ownership after the runtime and database selection change', function (): void {
    [$root, $pdo, $mysql] = host_mysql_fixture();
    $mysql->run($root, 'setup');
    file_put_contents($root.'/.ai-harness.config', "runtime=sail\n");
    // Mismatch prevents a real connection and proves cleanup reaches the ownership check
    // through the harness PHP, even when the newly selected runtime is unavailable.
    file_put_contents($root.'/.env', str_replace('3307', '3308', (string) file_get_contents($root.'/.env')));
    $process = harness_process(['cleanup'], $root);
    $process->run();
    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('restore its original endpoint')
        ->and($root.'/.ai-harness.state.json')->toBeFile();
});
