<?php

declare(strict_types=1);

use MrKoopie\LaravelAiHarness\Config\ConfigLoader;
use MrKoopie\LaravelAiHarness\Files\ClaudeSettings;
use MrKoopie\LaravelAiHarness\Files\ProjectInstaller;
use MrKoopie\LaravelAiHarness\Files\SafeWriter;
use Symfony\Component\Process\Process;

/** @return array{string, array<string, string>} */
function cloud_fixture(): array
{
    $root = temp_directory('harness-cloud');
    mkdir($root.'/vendor', 0755, true);
    mkdir($root.'/fake-bin', 0755, true);
    file_put_contents($root.'/vendor/autoload.php', "<?php\n");
    file_put_contents($root.'/composer.json', '{}');
    file_put_contents($root.'/composer.lock', '{}');
    file_put_contents($root.'/.env.example', "APP_KEY=present\nDB_CONNECTION=mysql\nDB_HOST=production.invalid\nDB_DATABASE=production\n");
    file_put_contents($root.'/.ai-harness.config', "runtime=herd\nservices=sail\nsail_services=mysql\nworktrees=false\ncloud_services=\n");
    write_executable($root.'/fake-bin/composer', <<<'BASH'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "$CLOUD_LOG"
exit "${COMPOSER_EXIT:-0}"
BASH);
    file_put_contents($root.'/artisan', '<?php file_put_contents(getenv("CLOUD_LOG"), implode(" ", array_slice($argv, 1))."\n", FILE_APPEND);');

    return [$root, [
        'AI_HARNESS_ENV' => 'codex-cloud',
        'CLAUDE_CODE_REMOTE' => 'false',
        'PATH' => $root.'/fake-bin'.PATH_SEPARATOR.getenv('PATH'),
        'CLOUD_LOG' => $root.'/commands.log',
    ]];
}

test('cloud setup and maintenance reconcile dependencies in native runtime', function (): void {
    [$root, $environment] = cloud_fixture();

    harness_process(['cloud', 'setup'], $root, $environment)->mustRun();
    harness_process(['cloud', 'maintain'], $root, $environment)->mustRun();

    expect($root.'/.env')->toBeFile()
        ->and($root.'/.env.testing')->toBeFile()
        ->and(file_get_contents($root.'/commands.log'))->toContain('check-platform-reqs', 'install --no-interaction --prefer-dist')
        ->and(substr_count((string) file_get_contents($root.'/commands.log'), 'install --no-interaction'))->toBe(2);
});

test('cloud SQLite starts as a valid database and retains development rows on maintenance', function (): void {
    [$root, $environment] = cloud_fixture();
    harness_process(['cloud', 'setup'], $root, $environment)->mustRun();
    $database = new PDO('sqlite:'.$root.'/database/database.sqlite');
    $database->exec('CREATE TABLE proof (value TEXT)');
    $database->exec("INSERT INTO proof VALUES ('preserved')");
    harness_process(['cloud', 'maintain'], $root, $environment)->mustRun();

    $rows = $database->query('SELECT value FROM proof');

    if ($rows === false) {
        throw new RuntimeException('Unable to read the preserved SQLite fixture.');
    }

    expect($rows->fetchColumn())->toBe('preserved');
});

test('cloud dependency failure stops setup before application commands', function (): void {
    [$root, $environment] = cloud_fixture();
    $environment['COMPOSER_EXIT'] = '23';
    $process = harness_process(['cloud', 'setup'], $root, $environment);
    $process->run();

    expect($process->getExitCode())->toBe(23)
        ->and(file_get_contents($root.'/commands.log'))->not->toContain('config:clear', 'migrate');
});

test('Claude cloud hooks prepare ordinary clones with worktree automation disabled', function (): void {
    [$root, $environment] = cloud_fixture();
    $environment['AI_HARNESS_ENV'] = false;
    $environment['CLAUDE_CODE_REMOTE'] = 'true';
    $environment['CLAUDE_PROJECT_DIR'] = $root;
    $process = harness_process(['hook', 'claude', 'session-start'], $root, $environment);
    $process->setInput(json_encode(['cwd' => $root], JSON_THROW_ON_ERROR));
    $process->mustRun();

    expect($root.'/.env.testing')->toBeFile();
});

test('Claude cloud hook rejects a target outside its project', function (): void {
    [$root, $environment] = cloud_fixture();
    $environment['AI_HARNESS_ENV'] = 'claude-cloud';
    $environment['CLAUDE_PROJECT_DIR'] = temp_directory('cloud-other');
    $process = harness_process(['hook', 'claude', 'session-start'], $root, $environment);
    $process->setInput(json_encode(['cwd' => $root], JSON_THROW_ON_ERROR));
    $process->run();

    expect($process->getExitCode())->toBe(1)
        ->and($root.'/.env')->not->toBeFile();
});

test('explicit local override prevents cloud setup even with Claude remote marker', function (): void {
    [$root, $environment] = cloud_fixture();
    $environment['AI_HARNESS_ENV'] = 'local';
    $environment['CLAUDE_CODE_REMOTE'] = 'true';
    $process = harness_process(['cloud', 'setup'], $root, $environment);
    $process->run();

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput().$process->getOutput())->toContain('requires a cloud environment')
        ->and($root.'/.env')->not->toBeFile();
});

test('cloud runtime commands bypass configured Herd and Sail', function (): void {
    [$root, $environment] = cloud_fixture();
    harness_process(['artisan', 'about'], $root, $environment)->mustRun();

    expect(file_get_contents($root.'/commands.log'))->toBe("about\n");
});

test('cloud cleanup is a no-op without owned cloud databases', function (): void {
    [$root, $environment] = cloud_fixture();
    file_put_contents($root.'/.env', 'KEEP DEVELOPMENT');
    harness_process(['cloud', 'cleanup'], $root, $environment)->mustRun();

    expect(file_get_contents($root.'/.env'))->toBe('KEEP DEVELOPMENT')
        ->and($root.'/commands.log')->not->toBeFile();
});

test('cloud provision refuses local execution before invoking system package managers', function (): void {
    $process = new Process(['bash', package_root().'/resources/project/cloud.sh', 'provision'], package_root(), [
        'AI_HARNESS_ENV' => 'local',
        'CLAUDE_CODE_REMOTE' => 'true',
    ]);
    $process->run();

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('requires a cloud environment');
});

test('cloud provisioning can isolate distribution sources from blocked third party repositories', function (): void {
    $root = temp_directory('cloud-apt');
    mkdir($root.'/bin');
    file_put_contents($root.'/ubuntu.sources', 'Types: deb');

    foreach (['id' => '0', 'uname' => 'Linux'] as $command => $output) {
        write_executable($root.'/bin/'.$command, "#!/bin/sh\necho ".$output."\n");
    }

    foreach (['apt-get', 'update-alternatives', 'php', 'composer', 'node', 'npm'] as $command) {
        write_executable($root.'/bin/'.$command, "#!/bin/sh\nprintf '%s\\n' \"\$*\" >> \"\$CLOUD_LOG\"\n");
    }

    $process = new Process(['bash', package_root().'/resources/project/cloud.sh', 'provision'], $root, [
        'AI_HARNESS_ENV' => 'codex-cloud',
        'AI_HARNESS_APT_SOURCE_LIST' => $root.'/ubuntu.sources',
        'PATH' => $root.'/bin'.PATH_SEPARATOR.getenv('PATH'),
        'CLOUD_LOG' => $root.'/commands',
    ]);
    $process->mustRun();
    $commands = (string) file_get_contents($root.'/commands');

    expect($commands)->toContain('-o Dir::Etc::sourcelist='.$root.'/ubuntu.sources -o Dir::Etc::sourceparts=- update')
        ->and($commands)->toContain('-o Dir::Etc::sourcelist='.$root.'/ubuntu.sources -o Dir::Etc::sourceparts=- install');
});

test('cloud database cleanup matches exact numeric workers and preserves all other databases', function (): void {
    $root = temp_directory('cloud-mysql');
    mkdir($root.'/bin');
    write_executable($root.'/bin/id', "#!/bin/sh\necho 0\n");
    write_executable($root.'/bin/sudo', "#!/usr/bin/env bash\nshift\nexec \"\$@\"\n");
    write_executable($root.'/bin/mysql', <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail
[[ "${MYSQL_TEST_LOGIN_FILE:-}" == /dev/null ]] || exit 81
[[ "$*" != *--no-login-paths* ]] || exit 82
if [[ "$*" == *'SHOW DATABASES;'* ]]; then
    cat "$SCHEMAS"
else
    printf '%s\n' "$*" >> "$SQL_LOG"
fi
BASH);
    file_put_contents($root.'/schemas', "app_123\napp_123_testing\napp_123_testing_1\napp_123_testing_test_12\nappX123_testing_1\napp_123_testing_backup\napp_123_testing_1_extra\nother_testing_1\napp_123_testing_test_\n");
    $process = new Process(['bash', package_root().'/resources/cloud/mysql.sh', 'cleanup', 'app_123', 'app_123_testing', 'harness_123'], $root, [
        'PATH' => $root.'/bin'.PATH_SEPARATOR.getenv('PATH'),
        'SCHEMAS' => $root.'/schemas',
        'SQL_LOG' => $root.'/sql',
    ]);
    $process->mustRun();
    $sql = (string) file_get_contents($root.'/sql');

    expect(substr_count($sql, 'DROP DATABASE'))->toBe(3)
        ->and($sql)->toContain('`app_123_testing`', '`app_123_testing_1`', '`app_123_testing_test_12`')
        ->and($sql)->not->toContain('`app_123`', 'appX123', 'backup', 'extra', 'other_testing');
});

test('cloud MySQL startup falls back to the daemon when image login scripts break service startup', function (): void {
    $root = temp_directory('cloud-service');
    mkdir($root.'/bin');
    write_executable($root.'/bin/id', "#!/bin/sh\necho 0\n");
    write_executable($root.'/bin/service', "#!/bin/sh\nexit 2\n");
    write_executable($root.'/bin/install', "#!/bin/sh\nexit 0\n");
    write_executable($root.'/bin/mysqld', "#!/bin/sh\ntouch \"\$CLOUD_READY\"\n");
    write_executable($root.'/bin/mysql', <<<'BASH'
#!/usr/bin/env bash
[[ "${MYSQL_TEST_LOGIN_FILE:-}" == /dev/null ]] || exit 81
[[ "$*" != *--no-login-paths* ]] || exit 82
test -f "$CLOUD_READY"
BASH);
    $process = new Process(['bash', package_root().'/resources/cloud/service.sh', 'mysql'], $root, [
        'PATH' => $root.'/bin'.PATH_SEPARATOR.getenv('PATH'),
        'CLOUD_READY' => $root.'/ready',
    ]);
    $process->mustRun();

    expect($root.'/ready')->toBeFile();
});

test('cloud setup clears stale Laravel config before Composer and removes inherited database overrides', function (): void {
    [$root, $environment] = cloud_fixture();
    mkdir($root.'/bootstrap/cache', 0755, true);
    file_put_contents($root.'/bootstrap/cache/config.php', '<?php return ["database" => "external"];');
    file_put_contents($root.'/.env.example', "APP_KEY=present\nAPP_CONFIG_CACHE=/tmp/external-config.php\n", FILE_APPEND);
    $environment['DB_HOST'] = 'external.invalid';
    $environment['DB_URL'] = 'mysql://external.invalid/app';
    write_executable($root.'/fake-bin/composer', <<<'BASH'
#!/usr/bin/env bash
[[ ! -f bootstrap/cache/config.php && -z "${DB_HOST:-}" && -z "${DB_URL:-}" ]]
BASH);

    harness_process(['cloud', 'setup'], $root, $environment)->mustRun();
    expect(file_get_contents($root.'/.env'))->toContain('DB_HOST=127.0.0.1', 'DB_URL=', 'APP_CONFIG_CACHE=bootstrap/cache/config.php')
        ->and(file_get_contents($root.'/.env'))->not->toContain('/tmp/external-config.php');
});

test('cloud setup runs locked frontend installation and configured build and migrations', function (): void {
    [$root, $environment] = cloud_fixture();
    file_put_contents($root.'/.ai-harness.config.local', "cloud_build=true\ncloud_migrate=true\ncloud_seed=true\n");
    file_put_contents($root.'/package.json', '{}');
    file_put_contents($root.'/package-lock.json', '{}');
    write_executable($root.'/fake-bin/npm', <<<'BASH'
#!/usr/bin/env bash
printf 'npm %s\n' "$*" >> "$CLOUD_LOG"
BASH);

    harness_process(['cloud', 'setup'], $root, $environment)->mustRun();
    expect(file_get_contents($root.'/commands.log'))->toContain('npm ci --include=dev', 'npm run build', 'migrate --force --no-interaction', 'migrate --force --no-interaction --env=testing', 'db:seed --force --no-interaction');
});

test('cloud setup refuses a symlinked application environment', function (): void {
    [$root, $environment] = cloud_fixture();
    $outside = temp_file('cloud-env');
    file_put_contents($outside, 'untouched');
    symlink($outside, $root.'/.env');
    $process = harness_process(['cloud', 'setup'], $root, $environment);
    $process->run();

    expect($process->getExitCode())->toBe(1)
        ->and(file_get_contents($outside))->toBe('untouched')
        ->and($root.'/commands.log')->not->toBeFile();
});

test('cloud cleanup refuses copied ownership from another checkout', function (): void {
    [$root, $environment] = cloud_fixture();
    file_put_contents($root.'/.ai-harness.state.json', '{"cloud_testing_database":"another_testing"}');
    $process = harness_process(['cloud', 'cleanup'], $root, $environment);
    $process->run();

    expect($process->getExitCode())->toBe(1)
        ->and($root.'/.ai-harness.state.json')->toBeFile()
        ->and($root.'/commands.log')->not->toBeFile();
});

test('Claude session end does not invoke development cleanup locally', function (): void {
    [$root, $environment] = cloud_fixture();
    $environment['AI_HARNESS_ENV'] = 'local';
    $environment['CLAUDE_PROJECT_DIR'] = $root;
    file_put_contents($root.'/.ai-harness.state.json', '{"mysql_databases":true}');
    $process = harness_process(['hook', 'claude', 'session-end'], $root, $environment);
    $process->setInput(json_encode(['cwd' => $root], JSON_THROW_ON_ERROR));
    $process->mustRun();

    expect(file_get_contents($root.'/.ai-harness.state.json'))->toBe('{"mysql_databases":true}');
});

test('cloud MySQL setup owns only its local databases and updates forced PHPUnit connections', function (): void {
    [$root, $environment] = cloud_fixture();
    file_put_contents($root.'/.ai-harness.config.local', "cloud_services=mysql\n");
    file_put_contents($root.'/phpunit.xml', '<phpunit><php><env value="production" name="DB_DATABASE" force="true"/><env name="DB_HOST" value="external.invalid" force="true"/><server name="DB_CONNECTION" value="sqlite"/></php></phpunit>');
    write_executable($root.'/fake-bin/sudo', "#!/usr/bin/env bash\nshift\nexec \"\$@\"\n");
    write_executable($root.'/fake-bin/service', "#!/usr/bin/env bash\nexit 0\n");
    write_executable($root.'/fake-bin/mysql', <<<'BASH'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "$CLOUD_LOG"
BASH);

    harness_process(['cloud', 'setup'], $root, $environment)->mustRun();
    $state = json_decode((string) file_get_contents($root.'/.ai-harness.state.json'), true);
    expect($state['cloud_testing_database'])->toEndWith('_testing')
        ->and(file_get_contents($root.'/phpunit.xml'))->not->toContain('production', 'external.invalid', 'value="sqlite"')
        ->and(file_get_contents($root.'/.env'))->toContain('DB_SOCKET=/var/run/mysqld/mysqld.sock');
});

test('generated Claude cloud hooks work without worktrees and preserve cleanup ownership on SQL failure', function (): void {
    [$root, $environment] = cloud_fixture();
    $environment['AI_HARNESS_ENV'] = 'claude-cloud';
    $environment['CLAUDE_PROJECT_DIR'] = $root;
    file_put_contents($root.'/.ai-harness.config.local', "cloud_services=mysql\n");
    $writer = new SafeWriter;
    $installer = new ProjectInstaller($writer, new ClaudeSettings($writer));
    $installer->install($root, (new ConfigLoader)->load($root));
    $installer->install($root, (new ConfigLoader)->load($root));
    write_executable($root.'/vendor/bin/ai-harness', "#!/bin/sh\nexec ".escapeshellarg(package_root().'/bin/ai-harness').' "$@"');
    write_executable($root.'/fake-bin/sudo', "#!/usr/bin/env bash\nshift\nexec \"\$@\"\n");
    write_executable($root.'/fake-bin/service', "#!/usr/bin/env bash\nexit 0\n");
    write_executable($root.'/fake-bin/mysql', <<<'BASH'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "$CLOUD_LOG"
exit "${MYSQL_EXIT:-0}"
BASH);
    $settings = json_decode((string) file_get_contents($root.'/.claude/settings.json'), true);

    foreach (['SessionStart', 'SessionEnd'] as $event) {
        expect($settings['hooks'][$event])->toHaveCount(1);
        $process = Process::fromShellCommandline($settings['hooks'][$event][0]['hooks'][0]['command'], $root, $environment);
        $process->setInput(json_encode(['cwd' => $root], JSON_THROW_ON_ERROR));
        $process->mustRun();
    }

    $state = (string) file_get_contents($root.'/.ai-harness.state.json');
    $development = (string) file_get_contents($root.'/.env');
    $environment['MYSQL_EXIT'] = '27';
    $process = harness_process(['hook', 'claude', 'session-end'], $root, $environment);
    $process->setInput(json_encode(['cwd' => $root], JSON_THROW_ON_ERROR));
    $process->run();

    expect($process->getExitCode())->toBe(27)
        ->and(file_get_contents($root.'/.ai-harness.state.json'))->toBe($state)
        ->and(file_get_contents($root.'/.env'))->toBe($development)
        ->and(file_get_contents($root.'/commands.log'))->toContain('SHOW DATABASES;')
        ->and($settings['hooks'])->not->toHaveKey('WorktreeRemove');
});
