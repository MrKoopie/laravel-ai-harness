<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

test('Codex init generates persistent command overrides and a complete retryable lifecycle without provisioning', function (): void {
    $root = temp_directory('harness-codex-config');
    (new Process(['git', 'init', $root]))->mustRun();
    mkdir($root.'/.codex');
    file_put_contents($root.'/.codex/config.toml', "model = \"existing-model\"\n[shell_environment_policy.set]\nCUSTOM = \"keep\"\nAI_HARNESS_RUNTIME = \"native\"\n[features]\nexample = true\n");
    file_put_contents($root.'/composer.json', "{}\n");
    $arguments = ['init', '--global', '--agent=codex', '--runtime=yerd', '--php-version=8.5', '--database=mysql', '--migrate', '--build'];
    harness_process($arguments, $root)->mustRun();
    $config = file_get_contents($root.'/.codex/config.toml');
    $environment = file_get_contents($root.'/.codex/environments/ai-harness-global.toml');
    expect($config)->toContain('CUSTOM = "keep"', 'model = "existing-model"', '[features]', 'AI_HARNESS_RUNTIME = "yerd"', 'AI_HARNESS_DB_CONNECTION = "mysql"')
        ->and(substr_count((string) $config, 'AI_HARNESS_RUNTIME ='))->toBe(1)
        ->and($environment)->toContain('export AI_HARNESS_PHP_VERSION=', 'setup --migrate --build', 'cleanup && ai-harness down')
        ->and(file_get_contents($root.'/.ai-harness.config.global'))->not->toContain('runtime=yerd', 'php_version=8.5')
        ->and($root.'/.ai-harness.state.json')->not->toBeFile()
        ->and($root.'/.env')->not->toBeFile()
        ->and($root.'/.claude/settings.local.json')->not->toBeFile();
    harness_process($arguments, $root)->mustRun();
    expect(file_get_contents($root.'/.codex/config.toml'))->toBe($config)
        ->and(file_get_contents($root.'/.codex/environments/ai-harness-global.toml'))->toBe($environment);
    harness_process(['update'], $root)->mustRun();
    expect(file_get_contents($root.'/.codex/environments/ai-harness-global.toml'))->toBe($environment);
    (new Process(['git', 'check-ignore', '.codex/config.toml'], $root))->mustRun();
});

test('Codex generation rejects invalid choices before changing project files', function (): void {
    $root = temp_directory('harness-codex-invalid');
    $process = harness_process(['init', '--agent=codex', '--runtime=invalid'], $root);
    $process->run();
    expect($process->getExitCode())->not->toBe(0)
        ->and($root.'/.ai-harness.config')->not->toBeFile()
        ->and($root.'/.codex')->not->toBeDirectory();
});

/** @return array<string, mixed> */
function read_codex_toml(string $path): array
{
    $process = new Process(['python3', '-c', 'import json,sys,tomllib; print(json.dumps(tomllib.load(open(sys.argv[1], "rb"))))', $path]);
    $process->mustRun();

    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

test('generated Codex scripts execute setup and teardown with the same settings and propagate failure', function (): void {
    $root = temp_directory('harness-codex-lifecycle');
    (new Process(['git', 'init', $root]))->mustRun();
    harness_process(['init', '--global', '--agent=codex', '--runtime=native', '--migrate', '--build'], $root)->mustRun();
    $environment = read_codex_toml($root.'/.codex/environments/ai-harness-global.toml');
    $shell = read_codex_toml($root.'/.codex/config.toml');
    $log = temp_file('harness-codex-script-log');
    write_executable($root.'/fake-bin/ai-harness', <<<'BASH'
#!/usr/bin/env bash
printf '%s|%s|%s|%s\n' "$*" "$AI_HARNESS_RUNTIME" "$AI_HARNESS_PHP_VERSION" "$AI_HARNESS_DB_CONNECTION" >> "$HARNESS_LOG"
if [[ "$1" == "${FAIL_ACTION:-none}" ]]; then exit 23; fi
BASH);
    file_put_contents($root.'/bash-env', 'export PATH='.escapeshellarg($root.'/fake-bin').':"$PATH"'."\n");
    $env = ['BASH_ENV' => $root.'/bash-env', 'HARNESS_LOG' => $log];
    (new Process(['bash', '-c', $environment['setup']['script']], $root, $env))->mustRun();
    (new Process(['bash', '-c', $environment['cleanup']['script']], $root, $env))->mustRun();
    $lines = file($log, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        throw new RuntimeException('Unable to read lifecycle log.');
    }
    expect($lines)->toHaveCount(4)
        ->and($lines[1])->toBe('setup --migrate --build|native||auto')
        ->and($lines[2])->toBe('cleanup|native||auto')
        ->and($lines[3])->toBe('down|native||auto')
        ->and($shell['shell_environment_policy']['set']['AI_HARNESS_RUNTIME'])->toBe('native');
    file_put_contents($log, '');
    $failure = new Process(['bash', '-c', $environment['cleanup']['script']], $root, $env + ['FAIL_ACTION' => 'cleanup']);
    $failure->run();
    expect($failure->getExitCode())->toBe(23)
        ->and(file($log, FILE_IGNORE_NEW_LINES))->toHaveCount(1);
    file_put_contents($log, '');
    $failure = new Process(['bash', '-c', $environment['setup']['script']], $root, $env + ['FAIL_ACTION' => 'init']);
    $failure->run();
    expect($failure->getExitCode())->toBe(23)
        ->and(file($log, FILE_IGNORE_NEW_LINES))->toHaveCount(1);
});

test('setup migration and frontend steps execute in order and stop on failure', function (): void {
    $root = temp_directory('harness-codex-app-setup');
    mkdir($root.'/vendor');
    file_put_contents($root.'/vendor/autoload.php', '<?php');
    file_put_contents($root.'/.env', "APP_KEY=existing\n");
    file_put_contents($root.'/.ai-harness.config', "runtime=native\nagents=\n");
    file_put_contents($root.'/package-lock.json', '{}');
    file_put_contents($root.'/artisan', <<<'PHP_ARTISAN'
<?php
file_put_contents(getenv('HARNESS_LOG'), implode(' ', array_slice($argv, 1))."\n", FILE_APPEND);
exit((int) getenv('MIGRATE_EXIT'));
PHP_ARTISAN);
    write_executable($root.'/fake-bin/npm', <<<'BASH'
#!/usr/bin/env bash
printf 'npm %s\n' "$*" >> "$HARNESS_LOG"
if [[ "$1" == ci ]]; then exit "${NPM_EXIT:-0}"; fi
BASH);
    $log = temp_file('harness-app-setup-log');
    $env = ['HARNESS_LOG' => $log, 'PATH' => $root.'/fake-bin'.PATH_SEPARATOR.getenv('PATH')];
    harness_process(['setup', '--migrate', '--build'], $root, $env)->mustRun();
    expect(file($log, FILE_IGNORE_NEW_LINES))->toBe(['migrate --force --no-interaction', 'npm ci --include=dev', 'npm run build']);
    foreach ([['MIGRATE_EXIT' => '17'], ['NPM_EXIT' => '18']] as $failure) {
        file_put_contents($log, '');
        $process = harness_process(['setup', '--migrate', '--build'], $root, $env + $failure);
        $process->run();
        expect($process->getExitCode())->toBe((int) array_values($failure)[0])
            ->and(file($log, FILE_IGNORE_NEW_LINES))->toHaveCount(isset($failure['MIGRATE_EXIT']) ? 1 : 2);
    }
});

foreach ([
    'multiline strings' => 'instructions = """a multiline string"""',
    'inline table' => 'shell_environment_policy = { set = { AI_HARNESS_RUNTIME = "native" } }',
    'dotted keys' => "[shell_environment_policy]\nset.AI_HARNESS_RUNTIME = \"native\"\n",
    'duplicate table' => "[shell_environment_policy.set]\n[shell_environment_policy.set]\n",
] as $case => $contents) {
    test('Codex generation refuses '.$case.' without changing the original file', function () use ($contents): void {
        $root = temp_directory('harness-codex-ambiguous');
        mkdir($root.'/.codex');
        file_put_contents($root.'/.codex/config.toml', $contents);
        $process = harness_process(['init', '--agent=codex'], $root);
        $process->run();
        expect($process->getExitCode())->toBe(1)
            ->and(file_get_contents($root.'/.codex/config.toml'))->toBe($contents)
            ->and($root.'/.ai-harness.config')->not->toBeFile();
    });
}

test('explicit SQLite setup creates a checkout database and resets forced testing connections', function (): void {
    $root = temp_directory('harness-codex-sqlite');
    mkdir($root.'/vendor');
    file_put_contents($root.'/vendor/autoload.php', '<?php');
    file_put_contents($root.'/.env', "APP_KEY=\nDB_CONNECTION=mysql\nDB_DATABASE=real_app\n");
    file_put_contents($root.'/.ai-harness.config', "runtime=native\nagents=\ndb_connection=sqlite\n");
    file_put_contents($root.'/phpunit.xml', '<phpunit><php><env name="DB_CONNECTION" value="mysql"/><env name="DB_DATABASE" value="real_app"/></php></phpunit>');
    file_put_contents($root.'/artisan', <<<'PHP_ARTISAN'
<?php
$contents = file_get_contents('.env');
file_put_contents('.env', str_replace('APP_KEY=', 'APP_KEY=generated-key', $contents));
PHP_ARTISAN);
    harness_process(['setup'], $root)->mustRun();
    expect($root.'/database/database.sqlite')->toBeFile()
        ->and(file_get_contents($root.'/.env'))->toContain('DB_CONNECTION=sqlite', 'DB_DATABASE='.$root.'/database/database.sqlite')
        ->and(file_get_contents($root.'/.env.testing'))->toContain('DB_DATABASE=:memory:', 'APP_KEY=generated-key')
        ->and(file_get_contents($root.'/phpunit.xml'))->toContain('value=":memory:"');
});

test('all harness settings are accepted and preserved for both agents without provisioning', function (): void {
    $root = temp_directory('harness-all-parameters');
    (new Process(['git', 'init', $root]))->mustRun();
    $secret = 'literal $HOME $(touch not-executed) "quotes" = value';
    $args = ['init', '--global', '--agents=codex,claude', '--runtime=herd', '--php-version=8.5', '--site-secure=false',
        '--sail-services=mysql,redis', '--database=mysql', '--worktrees=true',
        '--cloud=true', '--cloud-services=mysql,redis', '--cloud-migrate=true', '--cloud-seed=true', '--cloud-build=true', '--cloud-browser=false',
        '--local-env=FORWARD_DB_PORT=3308', '--local-env=CUSTOM_TOKEN='.$secret];
    harness_process($args, $root)->mustRun();
    $settings = read_codex_toml($root.'/.codex/config.toml')['shell_environment_policy']['set'];
    $claude = json_decode((string) file_get_contents($root.'/.claude/settings.local.json'), true, flags: JSON_THROW_ON_ERROR);
    expect($settings)->toMatchArray([
        'AI_HARNESS_RUNTIME' => 'herd', 'AI_HARNESS_PHP_VERSION' => '8.5', 'AI_HARNESS_SITE_SECURE' => 'false',
        'AI_HARNESS_DB_CONNECTION' => 'mysql', 'AI_HARNESS_AGENTS' => 'codex,claude',
        'AI_HARNESS_SAIL_SERVICES' => 'mysql,redis', 'AI_HARNESS_WORKTREES' => 'true', 'AI_HARNESS_CLOUD' => 'true',
        'AI_HARNESS_CLOUD_SERVICES' => 'mysql,redis', 'AI_HARNESS_CLOUD_MIGRATE' => 'true', 'AI_HARNESS_CLOUD_SEED' => 'true',
        'AI_HARNESS_CLOUD_BUILD' => 'true', 'AI_HARNESS_CLOUD_BROWSER' => 'false',
    ])->toBe($claude['env'])
        ->and($settings['AI_HARNESS_AGENTS'])->toBe('codex,claude')
        ->and($settings['AI_HARNESS_SAIL_SERVICES'])->toBe('mysql,redis')
        ->and($settings['AI_HARNESS_CLOUD_SEED'])->toBe('true')
        ->and(json_decode($settings['AI_HARNESS_LOCAL_ENV'], true, flags: JSON_THROW_ON_ERROR))->toBe(['FORWARD_DB_PORT' => '3308', 'CUSTOM_TOKEN' => $secret])
        ->and($root.'/.ai-harness.state.json')->not->toBeFile()
        ->and($root.'/.env')->not->toBeFile()
        ->and($root.'/not-executed')->not->toBeFile()
        ->and(file_get_contents($root.'/.ai-harness.config.global'))->not->toContain('runtime=herd', 'CUSTOM_TOKEN');
    $environment = read_codex_toml($root.'/.codex/environments/ai-harness-global.toml');
    expect($environment['setup']['script'])->toContain('AI_HARNESS_CLOUD_SERVICES=', 'AI_HARNESS_LOCAL_ENV=', '--agents=')
        ->and($environment['cleanup']['script'])->toContain('AI_HARNESS_SAIL_SERVICES=', 'AI_HARNESS_LOCAL_ENV=')
        ->and($claude['hooks'])->toHaveKeys(['SessionStart', 'SessionEnd', 'PostToolUse', 'PreToolUse', 'WorktreeRemove']);
    $loaded = new Process([PHP_BINARY, '-r', 'require $argv[1]; echo json_encode((new \MrKoopie\LaravelAiHarness\Config\ConfigLoader)->load($argv[2])->environment());', package_root().'/vendor/autoload.php', $root], $root, $settings);
    $loaded->mustRun();
    expect(json_decode($loaded->getOutput(), true, flags: JSON_THROW_ON_ERROR))->toBe($settings);
    // Reinitialize from exported settings, but stub all provisioning and cleanup operations.
    write_executable($root.'/fake-bin/ai-harness', <<<'BASH'
#!/usr/bin/env bash
if [[ "$1" == init ]]; then exec "$HARNESS_PHP" "$HARNESS_BIN" "$@"; fi
"$HARNESS_PHP" -r 'file_put_contents(getenv("PARAMETER_LOG"), json_encode([getenv("AI_HARNESS_SAIL_SERVICES"), getenv("AI_HARNESS_CLOUD_SERVICES"), getenv("AI_HARNESS_LOCAL_ENV")])."\n", FILE_APPEND);'
BASH);
    file_put_contents($root.'/bash-env', 'export PATH='.escapeshellarg($root.'/fake-bin').':"$PATH"'."\n");
    $log = temp_file('harness-parameter-replay-log');
    $replayEnvironment = ['BASH_ENV' => $root.'/bash-env', 'HARNESS_PHP' => PHP_BINARY, 'HARNESS_BIN' => package_root().'/bin/ai-harness', 'PARAMETER_LOG' => $log];
    foreach (['setup', 'cleanup'] as $phase) {
        (new Process(['bash', '-c', $environment[$phase]['script']], $root, $replayEnvironment))->mustRun();
    }
    $lines = file($log, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        throw new RuntimeException('Unable to read parameter replay log.');
    }
    expect($lines)->toHaveCount(3);
    foreach ($lines as $line) {
        expect(json_decode($line, true, flags: JSON_THROW_ON_ERROR))->toBe(['mysql,redis', 'mysql,redis', $settings['AI_HARNESS_LOCAL_ENV']]);
    }
    expect($root.'/not-executed')->not->toBeFile()
        ->and(read_codex_toml($root.'/.codex/config.toml')['shell_environment_policy']['set'])->toBe($settings);

});

test('Claude-only parameters and disabled worktrees do not require Codex generation', function (): void {
    $root = temp_directory('harness-claude-parameters');
    harness_process(['init', '--agents=claude', '--runtime=native', '--worktrees=false', '--cloud=false', '--local-env=CUSTOM_FLAG='], $root)->mustRun();
    $settings = json_decode((string) file_get_contents($root.'/.claude/settings.json'), true, flags: JSON_THROW_ON_ERROR);
    expect($settings['env']['AI_HARNESS_AGENTS'])->toBe('claude')
        ->and($settings['env']['AI_HARNESS_WORKTREES'])->toBe('false')
        ->and($settings)->not->toHaveKey('hooks')
        ->and($root.'/.codex')->not->toBeDirectory();
});

foreach ([
    ['--agents=other'], ['--agent=codex', '--agents=claude'], ['--cloud-seed=true', '--cloud-migrate=false'],
    ['--worktrees=maybe'], ['--local-env=lowercase=value'], ['--local-env=PORT=1', '--local-env=PORT=2'],
    ['--local-env=APP_KEY=secret'], ['--database=mysql', '--db-connection=sqlite'],
] as $index => $arguments) {
    test('invalid parameter selection fails before writes '.$index, function () use ($arguments): void {
        $root = temp_directory('harness-invalid-parameters');
        $process = harness_process(['init', ...$arguments], $root);
        $process->run();
        expect($process->getExitCode())->not->toBe(0)
            ->and($root.'/.ai-harness.config')->not->toBeFile()
            ->and($root.'/.codex')->not->toBeDirectory()
            ->and($root.'/.claude')->not->toBeDirectory();
    });
}

test('repeated agent options support multiple agents and worktree automation can be disabled', function (): void {
    $root = temp_directory('harness-repeated-agents');
    harness_process(['init', '--agent=codex', '--agent=claude', '--worktrees=false', '--cloud=false'], $root)->mustRun();
    $variables = read_codex_toml($root.'/.codex/config.toml')['shell_environment_policy']['set'];
    expect($variables['AI_HARNESS_AGENTS'])->toBe('codex,claude')
        ->and($variables['AI_HARNESS_WORKTREES'])->toBe('false')
        ->and($root.'/.codex/environments/environment.toml')->not->toBeFile()
        ->and($root.'/AGENTS.md')->toBeFile();
});

test('empty service lists and cleared local values survive regeneration and inherited Claude initialization', function (): void {
    $root = temp_directory('harness-empty-parameters');
    (new Process(['git', 'init', $root]))->mustRun();
    mkdir($root.'/.claude');
    file_put_contents($root.'/.claude/settings.local.json', '{"env":{"USER_VALUE":"keep"},"permissions":{"deny":["Bash(dangerous)"]}}');
    harness_process(['init', '--global', '--agents=claude', '--worktrees=false', '--cloud=false', '--sail-services=', '--cloud-services=', '--local-env=CUSTOM_VALUE='], $root)->mustRun();
    $settings = json_decode((string) file_get_contents($root.'/.claude/settings.local.json'), true, flags: JSON_THROW_ON_ERROR);
    expect($settings['env']['USER_VALUE'])->toBe('keep')
        ->and($settings['permissions']['deny'])->toBe(['Bash(dangerous)'])
        ->and($settings['env']['AI_HARNESS_SAIL_SERVICES'])->toBe('')
        ->and($settings['env']['AI_HARNESS_CLOUD_SERVICES'])->toBe('')
        ->and(json_decode($settings['env']['AI_HARNESS_LOCAL_ENV'], true, flags: JSON_THROW_ON_ERROR))->toBe(['CUSTOM_VALUE' => '']);
    $fresh = temp_directory('harness-inherited-claude-parameters');
    (new Process(['git', 'init', $fresh]))->mustRun();
    harness_process(['init', '--global'], $fresh, $settings['env'])->mustRun();
    $inherited = json_decode((string) file_get_contents($fresh.'/.claude/settings.local.json'), true, flags: JSON_THROW_ON_ERROR);
    unset($settings['env']['USER_VALUE']);
    expect($inherited['env'])->toBe($settings['env'])
        ->and($inherited)->not->toHaveKey('hooks');
});

test('malformed Claude environment settings are rejected before integration writes', function (): void {
    $root = temp_directory('harness-invalid-claude-environment');
    mkdir($root.'/.claude');
    $contents = '{"env":["wrong-shape"]}';
    file_put_contents($root.'/.claude/settings.json', $contents);
    $process = harness_process(['init', '--agents=codex,claude', '--cloud=false'], $root);
    $process->run();
    expect($process->getExitCode())->toBe(1)
        ->and(file_get_contents($root.'/.claude/settings.json'))->toBe($contents)
        ->and($root.'/.ai-harness.config')->not->toBeFile()
        ->and($root.'/.codex')->not->toBeDirectory();
});

test('regeneration removes obsolete service manager exports from both agents', function (): void {
    $root = temp_directory('harness-remove-service-manager');
    (new Process(['git', 'init', $root]))->mustRun();
    mkdir($root.'/.codex');
    mkdir($root.'/.claude');
    file_put_contents($root.'/.codex/config.toml', "[shell_environment_policy.set]\nAI_HARNESS_SERVICE_MANAGER = \"sail\"\nCUSTOM = \"keep\"\n");
    file_put_contents($root.'/.claude/settings.local.json', json_encode(['env' => ['AI_HARNESS_SERVICE_MANAGER' => 'sail', 'CUSTOM' => 'keep']], JSON_THROW_ON_ERROR));
    harness_process(['init', '--global', '--agents=codex,claude', '--runtime=native', '--sail-services='], $root)->mustRun();
    $codex = read_codex_toml($root.'/.codex/config.toml');
    $claudeContents = file_get_contents($root.'/.claude/settings.local.json');
    if ($claudeContents === false) {
        throw new RuntimeException('Unable to read generated Claude settings.');
    }
    $claude = json_decode($claudeContents, true, flags: JSON_THROW_ON_ERROR);
    expect($codex['shell_environment_policy']['set'])->not->toHaveKey('AI_HARNESS_SERVICE_MANAGER')
        ->and($codex['shell_environment_policy']['set']['CUSTOM'])->toBe('keep')
        ->and($claude['env'])->not->toHaveKey('AI_HARNESS_SERVICE_MANAGER')
        ->and($claude['env']['CUSTOM'])->toBe('keep')
        ->and(file_get_contents($root.'/.codex/environments/ai-harness-global.toml'))->not->toContain('AI_HARNESS_SERVICE_MANAGER');
});
