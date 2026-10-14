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

foreach (['DB_URL', 'DATABASE_URL', 'DB_SOCKET', 'REDIS_URL', 'APP_CONFIG_CACHE'] as $alias) {
    test('every duplicate alias is checked before applying overrides: '.$alias, function () use ($alias): void {
        $root = temp_directory('harness-duplicate-alias');
        mkdir($root.'/vendor');
        file_put_contents($root.'/vendor/autoload.php', '<?php');
        $endpoint = $alias === 'REDIS_URL' ? 'REDIS_PORT' : 'DB_PORT';
        $contents = "APP_KEY=present\n{$alias}=\nexport {$alias}=private-value\n";
        file_put_contents($root.'/.env', $contents);
        file_put_contents($root.'/.ai-harness.config.local', "agents=\nlocal_env.{$endpoint}=3307\n");
        $process = harness_process(['setup'], $root);
        $process->run();

        expect($process->getExitCode())->toBe(1)
            ->and($process->getOutput().$process->getErrorOutput())->toContain($alias)
            ->and(file_get_contents($root.'/.env'))->toBe($contents);

        expect($process->getOutput().$process->getErrorOutput())->not->toContain('private-value');

        file_put_contents($root.'/.ai-harness.config.local', "agents=\nlocal_env.{$endpoint}=3307\nlocal_env.{$alias}=\n");

        if ($alias === 'APP_CONFIG_CACHE') {
            // APP_CONFIG_CACHE is managed; clear it directly rather than configuring an override.
            file_put_contents($root.'/.env', "APP_KEY=present\nAPP_CONFIG_CACHE=\n");
            file_put_contents($root.'/.ai-harness.config.local', "agents=\nlocal_env.{$endpoint}=3307\n");
        }

        harness_process(['setup'], $root)->mustRun();

        expect(file_get_contents($root.'/.env'))->toContain("{$alias}=\n")
            ->and(substr_count((string) file_get_contents($root.'/.env'), $alias.'='))->toBe(1);
    });
}

foreach (['DB_URL', 'DATABASE_URL', 'DB_SOCKET'] as $alias) {
    test('switching DB_CONNECTION checks inherited aliases: '.$alias, function () use ($alias): void {
        $root = temp_directory('harness-connection-switch');
        mkdir($root.'/vendor');
        file_put_contents($root.'/vendor/autoload.php', '<?php');
        $contents = "APP_KEY=present\nDB_CONNECTION=mysql\n{$alias}=private-value\n";
        file_put_contents($root.'/.env', $contents);
        file_put_contents($root.'/.ai-harness.config.local', "agents=\nlocal_env.DB_CONNECTION=sqlite\n");
        $process = harness_process(['setup'], $root);
        $process->run();

        expect($process->getExitCode())->toBe(1)
            ->and($process->getOutput().$process->getErrorOutput())->toContain($alias)
            ->and(file_get_contents($root.'/.env'))->toBe($contents);

        file_put_contents($root.'/.ai-harness.config.local', "agents=\nlocal_env.DB_CONNECTION=sqlite\nlocal_env.{$alias}=\n");
        harness_process(['setup'], $root)->mustRun();

        expect(file_get_contents($root.'/.env'))->toContain('DB_CONNECTION=sqlite', "{$alias}=\n");
    });
}

foreach ([
    'DB_CONNECTION' => 'pgsql',
    'DB_HOST' => 'private-value',
    'DB_PORT' => '3310',
    'DB_DATABASE' => 'private-value',
    'DB_USERNAME' => 'private-value',
    'DB_PASSWORD' => 'private-value',
    'DB_URL' => 'private-value',
    'DATABASE_URL' => 'private-value',
    'DB_SOCKET' => 'private-value',
    'MYSQL_ATTR_SSL_CA' => 'private-value',
] as $name => $value) {
    test('managed MySQL rejects conflicting process settings before changes: '.$name, function () use ($name, $value): void {
        $root = temp_directory('harness-managed-process');
        mkdir($root.'/vendor/bin', 0755, true);
        file_put_contents($root.'/vendor/autoload.php', '<?php');
        file_put_contents($root.'/.env', "APP_KEY=present\nFORWARD_DB_PORT=3306\n");
        file_put_contents($root.'/.ai-harness.config', "services=sail\nsail_services=mysql\nagents=\n");
        file_put_contents($root.'/.ai-harness.config.local', 'local_env.FORWARD_DB_PORT=3307');
        write_executable($root.'/vendor/bin/sail', "#!/usr/bin/env bash\ntouch command-ran\n");
        $environment = array_fill_keys(['DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'DB_URL', 'DATABASE_URL', 'DB_SOCKET', 'MYSQL_ATTR_SSL_CA'], false);
        $environment[$name] = $value;
        $process = harness_process(['setup'], $root, $environment);
        $process->run();

        expect($process->getExitCode())->toBe(1)
            ->and($process->getOutput().$process->getErrorOutput())->toContain('process '.$name)
            ->and(file_get_contents($root.'/.env'))->toBe("APP_KEY=present\nFORWARD_DB_PORT=3306\n")
            ->and($root.'/command-ran')->not->toBeFile();

        expect($process->getOutput().$process->getErrorOutput())->not->toContain('private-value');
    });
}

foreach (['native', 'sail'] as $runtime) {
    foreach (['local override' => '3307', 'template default' => '3311', 'existing environment' => '3312'] as $source => $port) {
        test('matching managed process endpoints are accepted for '.$runtime.' with '.$source, function () use ($runtime, $source, $port): void {
            $root = temp_directory('harness-matching-managed-process');
            mkdir($root.'/vendor/bin', 0755, true);
            file_put_contents($root.'/vendor/autoload.php', '<?php');
            file_put_contents($root.'/artisan', '<?php');
            $filename = $source === 'existing environment' ? '.env' : '.env.example';
            file_put_contents($root.'/'.$filename, "APP_KEY=present\nFORWARD_DB_PORT={$port}\n");
            file_put_contents($root.'/.ai-harness.config', "runtime={$runtime}\nservices=sail\nsail_services=mysql\nagents=\n");

            if ($source === 'local override') {
                file_put_contents($root.'/.ai-harness.config.local', 'local_env.FORWARD_DB_PORT=3307');
            }

            write_executable($root.'/vendor/bin/sail', "#!/usr/bin/env bash\nexit 0\n");
            $environment = [
                'DB_CONNECTION' => 'mysql',
                'DB_HOST' => $runtime === 'sail' ? 'mysql' : '127.0.0.1',
                'DB_PORT' => $runtime === 'sail' ? '3306' : $port,
                'DB_DATABASE' => false,
                'DB_USERNAME' => 'sail',
                'DB_PASSWORD' => 'password',
                'DB_URL' => false,
                'DATABASE_URL' => false,
                'DB_SOCKET' => false,
                'MYSQL_ATTR_SSL_CA' => false,
                'FORWARD_DB_PORT' => $port,
            ];
            harness_process(['setup'], $root, $environment)->mustRun();

            expect(file_get_contents($root.'/.env'))->toContain('DB_PORT='.$environment['DB_PORT'], 'DB_HOST='.$environment['DB_HOST'], 'DB_DATABASE='.DatabaseName::forPath($root))
                ->and(file_get_contents($root.'/.env.testing'))->toContain('DB_DATABASE='.DatabaseName::testingForPath($root));
        });
    }
}

test('even a matching development database in the process would override the isolated testing database', function (): void {
    $root = temp_directory('harness-process-testing-isolation');
    mkdir($root.'/vendor/bin', 0755, true);
    file_put_contents($root.'/vendor/autoload.php', '<?php');
    file_put_contents($root.'/.env', "APP_KEY=present\n");
    file_put_contents($root.'/.ai-harness.config', "services=sail\nsail_services=mysql\nagents=\n");
    write_executable($root.'/vendor/bin/sail', "#!/usr/bin/env bash\ntouch command-ran\n");
    $environment = ['DB_DATABASE' => DatabaseName::forPath($root)];
    $setup = harness_process(['setup'], $root, $environment);
    $setup->run();
    $doctor = harness_process(['doctor'], $root, $environment);
    $doctor->run();

    expect($setup->getExitCode())->toBe(1)
        ->and($setup->getOutput().$setup->getErrorOutput())->toContain('process DB_DATABASE')
        ->and($doctor->getOutput().$doctor->getErrorOutput())->toContain('process DB_DATABASE')
        ->and($root.'/command-ran')->not->toBeFile();
});
