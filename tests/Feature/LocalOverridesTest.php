<?php

declare(strict_types=1);

use MrKoopie\LaravelAiHarness\Environment\DatabaseName;
use Symfony\Component\Process\Process;

test('local setup reapplies personal environment values without changing unrelated values or testing defaults', function (): void {
    $root = temp_directory('harness-local-overrides');
    mkdir($root.'/vendor');
    file_put_contents($root.'/vendor/autoload.php', '<?php');
    file_put_contents($root.'/artisan', '<?php');
    file_put_contents($root.'/.env.example', "APP_KEY=present\nDB_PORT=3306\nCUSTOM_UNCHANGED=original\n");
    file_put_contents($root.'/.ai-harness.config.local', "agents=\nlocal_env.DB_PORT=3307\nlocal_env.MAIL_PORT=1026\nlocal_env.MAIL_MAILER=smtp\nlocal_env.CACHE_STORE=redis\nlocal_env.DB_URL=\n");

    harness_process(['setup'], $root)->mustRun();
    file_put_contents($root.'/.env', str_replace('DB_PORT=3307', 'DB_PORT=3306', (string) file_get_contents($root.'/.env')));
    harness_process(['setup'], $root)->mustRun();

    expect(file_get_contents($root.'/.env'))->toContain('DB_PORT=3307', 'MAIL_PORT=1026', 'CUSTOM_UNCHANGED=original')
        ->and(file_get_contents($root.'/.env.testing'))->toContain('DB_CONNECTION=sqlite', 'DB_DATABASE=:memory:', 'MAIL_MAILER=array', 'CACHE_STORE=array', "DB_URL=\n")
        ->and(file_get_contents($root.'/.env.example'))->toContain('DB_PORT=3306');
});

test('forwarded port overrides reach Sail before startup and configure both isolated databases', function (): void {
    $root = temp_directory('harness-forward-override');
    mkdir($root.'/vendor/bin', 0755, true);
    file_put_contents($root.'/vendor/autoload.php', '<?php');
    file_put_contents($root.'/artisan', '<?php');
    file_put_contents($root.'/.env.example', "APP_KEY=present\nFORWARD_DB_PORT=3306\nDB_URL=mysql://stale.invalid/shared\nDB_SOCKET=/stale.sock\n");
    file_put_contents($root.'/.ai-harness.config', "services=sail\nsail_services=mysql\nagents=\n");
    file_put_contents($root.'/.ai-harness.config.local', "local_env.FORWARD_DB_PORT=3307\n");
    write_executable($root.'/vendor/bin/sail', <<<'BASH'
#!/usr/bin/env bash
if [[ "$1" == "up" ]]; then
    cp .env started.env
fi
BASH);

    harness_process(['setup'], $root)->mustRun();

    expect(file_get_contents($root.'/started.env'))->toContain('FORWARD_DB_PORT=3307', 'DB_PORT=3307')
        ->and(file_get_contents($root.'/.env'))->toContain('DB_DATABASE='.DatabaseName::forPath($root), "DB_URL=\n", "DB_SOCKET=\n")
        ->and(file_get_contents($root.'/.env.testing'))->toContain('DB_PORT=3307', 'DB_DATABASE='.DatabaseName::testingForPath($root));
});

test('inside Sail the forwarded port does not change the container database port', function (): void {
    $root = temp_directory('harness-container-override');
    mkdir($root.'/vendor/bin', 0755, true);
    file_put_contents($root.'/vendor/autoload.php', '<?php');
    file_put_contents($root.'/.env.example', "APP_KEY=present\n");
    file_put_contents($root.'/.ai-harness.config.local', "runtime=sail\nservices=sail\nsail_services=mysql\nlocal_env.FORWARD_DB_PORT=3307\n");
    write_executable($root.'/vendor/bin/sail', "#!/usr/bin/env bash\nexit 0\n");

    harness_process(['setup'], $root)->mustRun();

    expect(file_get_contents($root.'/.env'))->toContain('FORWARD_DB_PORT=3307', 'DB_HOST=mysql', 'DB_PORT=3306');
});

test('doctor reports override origins without disclosing values and detects endpoint and cache conflicts', function (): void {
    $root = temp_directory('harness-override-doctor');
    file_put_contents($root.'/.ai-harness.config.local', "agents=\nlocal_env.DB_PORT=3307\nlocal_env.DB_PASSWORD=very-secret-value\n");
    file_put_contents($root.'/.env', "DB_URL=mysql://private.invalid/database\nDB_SOCKET=/private.sock\n");
    mkdir($root.'/bootstrap/cache', 0755, true);
    file_put_contents($root.'/bootstrap/cache/config.php', '<?php return [];');
    $process = harness_process(['doctor'], $root);
    $process->run();
    $output = $process->getOutput().$process->getErrorOutput();

    expect($output)->toContain('DB_PORT', '.ai-harness.config.local', 'DB_PASSWORD', 'DB_URL', 'DB_SOCKET', 'cached');

    expect($output)->not->toContain('very-secret-value', 'private.invalid', '/private.sock');
});

test('local setup refuses URL conflicts before making changes or running application commands', function (): void {
    $root = temp_directory('harness-url-conflict');
    file_put_contents($root.'/.ai-harness.config.local', "local_env.DB_PORT=3307\n");
    file_put_contents($root.'/.env.example', "DB_URL=mysql://private.invalid/shared\n");
    $process = harness_process(['setup'], $root);
    $process->run();

    expect($process->getExitCode())->toBe(1)
        ->and($process->getOutput().$process->getErrorOutput())->toContain('DB_URL')
        ->and($root.'/.env')->not->toBeFile();

    expect($process->getOutput().$process->getErrorOutput())->not->toContain('private.invalid');
});

test('local setup clears cached configuration before Composer or Artisan and preserves literal values', function (): void {
    $root = temp_directory('harness-cache-override');
    mkdir($root.'/bootstrap/cache', 0755, true);
    file_put_contents($root.'/bootstrap/cache/config.php', '<?php return [];');
    file_put_contents($root.'/.env.example', "APP_KEY=present\nDB_PORT=3306\n");
    file_put_contents($root.'/.ai-harness.config.local', <<<'INI'
agents=
local_env.DB_PORT=3307
local_env.CUSTOM_VALUE='spaces # quotes " backslash \ and ${DB_PORT} $(touch should-not-exist)'
INI);
    write_executable($root.'/fake-bin/composer', <<<'BASH'
#!/usr/bin/env bash
test ! -e bootstrap/cache/config.php || exit 31
grep -q '^DB_PORT=3307$' .env || exit 32
mkdir -p vendor
printf '<?php' > vendor/autoload.php
BASH);
    $environment = ['PATH' => $root.'/fake-bin'.PATH_SEPARATOR.getenv('PATH')];

    harness_process(['setup'], $root, $environment)->mustRun();

    expect($root.'/bootstrap/cache/config.php')->not->toBeFile()
        ->and($root.'/should-not-exist')->not->toBeFile()
        ->and(file_get_contents($root.'/.env'))->toContain('DB_PORT=3307', '\\${DB_PORT}', '\\"', '\\\\');
});

test('a process level endpoint override is reported without exposing its value', function (): void {
    $root = temp_directory('harness-process-override');
    file_put_contents($root.'/.ai-harness.config.local', 'local_env.DB_PORT=3307');
    file_put_contents($root.'/.env.example', 'DB_PORT=3306');
    $process = harness_process(['setup'], $root, ['DB_PORT' => '3319', 'DB_URL' => false, 'DATABASE_URL' => false, 'DB_SOCKET' => false]);
    $process->run();

    expect($process->getExitCode())->toBe(1)
        ->and($process->getOutput().$process->getErrorOutput())->toContain('process DB_PORT')
        ->and($root.'/.env')->not->toBeFile();

    expect($process->getOutput().$process->getErrorOutput())->not->toContain('3319');
});

test('an empty process setting still takes precedence over a configured local value', function (): void {
    $root = temp_directory('harness-empty-process-override');
    file_put_contents($root.'/.ai-harness.config.local', 'local_env.DB_PORT=3307');
    file_put_contents($root.'/.env.example', 'DB_PORT=3306');
    $process = harness_process(['setup'], $root, ['DB_PORT' => '']);
    $process->run();

    expect($process->getExitCode())->toBe(1)
        ->and($process->getOutput().$process->getErrorOutput())->toContain('process DB_PORT')
        ->and($root.'/.env')->not->toBeFile();
});

test('missing environment templates produce an actionable error instead of silently ignoring overrides', function (): void {
    $root = temp_directory('harness-no-env-override');
    file_put_contents($root.'/.ai-harness.config.local', 'local_env.DB_PORT=3307');
    $process = harness_process(['setup'], $root);
    $process->run();

    expect($process->getExitCode())->toBe(1)
        ->and($process->getOutput().$process->getErrorOutput())->toContain('require .env or .env.example')
        ->and($root.'/.env')->not->toBeFile();
});

test('literal command substitutions remain inert when Sail sources the generated environment', function (): void {
    $root = temp_directory('harness-sail-literal');
    mkdir($root.'/vendor');
    file_put_contents($root.'/vendor/autoload.php', '<?php');
    file_put_contents($root.'/.env.example', "APP_KEY=present\nDB_PORT = 3306\nexport DB_PORT=3305\n");
    file_put_contents($root.'/.ai-harness.config.local', <<<'INI'
agents=
local_env.DB_PORT=3307
local_env.CUSTOM_TICKS=`touch tick-executed`
local_env.CUSTOM_APOSTROPHE="can't $(touch dollar-executed)"
INI);

    harness_process(['setup'], $root)->mustRun();
    $read = new Process(['bash', '-c', 'source .env; printf "%s\n%s\n%s" "$DB_PORT" "$CUSTOM_TICKS" "$CUSTOM_APOSTROPHE"'], $root);
    $read->mustRun();

    expect($read->getOutput())->toBe("3307\n`touch tick-executed`\ncan't $(touch dollar-executed)")
        ->and($root.'/tick-executed')->not->toBeFile()
        ->and($root.'/dollar-executed')->not->toBeFile()
        ->and(substr_count((string) file_get_contents($root.'/.env'), 'DB_PORT='))->toBe(1);
});
