<?php

declare(strict_types=1);

use MrKoopie\LaravelAiHarness\Config\ConfigException;
use MrKoopie\LaravelAiHarness\Config\ConfigLoader;
use MrKoopie\LaravelAiHarness\Console\PruneYerdCommand;
use MrKoopie\LaravelAiHarness\Environment\CommandFactory;
use MrKoopie\LaravelAiHarness\Environment\SiteName;
use MrKoopie\LaravelAiHarness\Process\ExecutableLocator;
use MrKoopie\LaravelAiHarness\Process\ProcessRunner;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;

/** @return array{string, array<string, string>} */
function yerd_fixture(): array
{
    $root = temp_directory('harness-yerd');
    mkdir($root.'/vendor');
    mkdir($root.'/public');
    file_put_contents($root.'/vendor/autoload.php', '<?php');
    file_put_contents($root.'/artisan', '<?php');
    file_put_contents($root.'/.env', "APP_KEY=present\nAPP_URL=http://shared.test\n");
    file_put_contents($root.'/.ai-harness.config', "runtime=yerd\nservice_manager=none\nagents=\nphp_version=8.2\n");
    file_put_contents($root.'/sites.json', '[]');
    file_put_contents($root.'/status.json', json_encode(['tld' => 'test', 'default_php' => '8.5', 'http' => ['bound' => 80], 'https' => ['bound' => 443]], JSON_THROW_ON_ERROR));
    file_put_contents($root.'/log', '');
    $script = <<<'PHP_SCRIPT'
<?php
if (basename($argv[0]) !== 'yerd') {
    echo json_encode(['shim' => basename($argv[0]), 'args' => array_slice($argv, 1)]);
    exit((int) (getenv('YERD_PHP_EXIT') ?: 0));
}
$root = getenv('YERD_FIXTURE');
$args = array_slice($argv, 1);
file_put_contents($root.'/log', json_encode($args)."\n", FILE_APPEND);
if ($args[0] === '--json') {
    if (getenv('YERD_BAD_JSON')) { echo 'broken'; exit(0); }
    if (getenv('YERD_QUERY_FAIL')) { exit(7); }
    echo json_encode($args[1] === 'sites'
        ? ['type' => 'sites', 'sites' => json_decode(file_get_contents($root.'/sites.json'), true)]
        : ['type' => 'status', 'report' => json_decode(file_get_contents($root.'/status.json'), true)]);
    exit(0);
}
if ($args[0] === getenv('YERD_FAIL')) { exit(19); }
$sites = json_decode(file_get_contents($root.'/sites.json'), true);
$name = $args[1];
if ($args[0] === 'link') {
    $sites[] = ['name' => $name, 'document_root' => $args[2], 'kind' => 'linked', 'php' => '8.5', 'secure' => false];
} else {
    foreach ($sites as $key => &$site) {
        if ($site['name'] !== $name) { continue; }
        if ($args[0] === 'unlink') { unset($sites[$key]); }
        if ($args[0] === 'secure' || $args[0] === 'unsecure') { $site['secure'] = $args[0] === 'secure'; }
        if ($args[0] === 'use') { $site['php'] = $args[2]; }
        if ($args[0] === 'unsecure' && getenv('YERD_REASSIGN')) { $site['document_root'] = '/another/app'; }
    }
}
file_put_contents($root.'/sites.json', json_encode(array_values($sites)));
PHP_SCRIPT;
    $script = str_replace("\$root = getenv('YERD_FIXTURE');", '$root = '.var_export($root, true).';', $script);
    write_executable($root.'/bin/yerd', '#!'.PHP_BINARY."\n".$script);
    foreach (['php', 'php8.2', 'php8.5'] as $shim) {
        symlink($root.'/bin/yerd', $root.'/bin/'.$shim);
    }
    // Keep native bootstrap execution out of the fake PHP shims.
    $env = ['PATH' => $root.'/bin'.PATH_SEPARATOR.getenv('PATH'), 'YERD_FIXTURE' => $root];

    return [$root, $env];
}

/** @return list<list<string>> */
function yerd_mutations(string $root): array
{
    $commands = [];
    foreach (file($root.'/log', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $command = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($command) || ! array_is_list($command)) {
            throw new RuntimeException('Invalid fixture command.');
        }
        foreach ($command as $argument) {
            if (! is_string($argument)) {
                throw new RuntimeException('Invalid fixture argument.');
            }
        }
        if (($command[0] ?? null) !== '--json') {
            $commands[] = $command;
        }
    }

    return $commands;
}

/** Execute the package CLI using real PHP rather than fixture shims.
 * @param  list<string>  $arguments
 * @param  array<string, string>  $environment
 */
function yerd_harness(array $arguments, string $root, array $environment): Process
{
    return new Process([PHP_BINARY, package_root().'/bin/ai-harness', ...$arguments], $root, $environment);
}

test('Yerd setup pins only this checkout and cleanup removes only its owned site', function (): void {
    [$root, $env] = yerd_fixture();
    $site = SiteName::forPath($root);
    foreach (['setup', 'setup'] as $action) {
        yerd_harness([$action], $root, $env)->mustRun();
    }
    expect(yerd_mutations($root))->toBe([
        ['link', $site, $root], ['root', $site, 'public'], ['front-controller', $site, 'on'], ['secure', $site], ['use', $site, '8.2'],
        ['root', $site, 'public'], ['front-controller', $site, 'on'], ['use', $site, '8.2'],
    ])->and(file_get_contents($root.'/.env'))->toContain('APP_URL=https://'.$site.'.test')
        ->and(file_get_contents($root.'/.env.testing'))->toContain('DB_DATABASE=:memory:');
    yerd_harness(['cleanup'], $root, $env)->mustRun();
    expect(array_slice(yerd_mutations($root), -2))->toBe([['unsecure', $site], ['unlink', $site]])
        ->and($root.'/.ai-harness.state.json')->not->toBeFile();
});

test('Yerd refuses unowned collisions and reassigned owned sites', function (): void {
    [$root, $env] = yerd_fixture();
    $site = SiteName::forPath($root);
    foreach ([false, true] as $owned) {
        file_put_contents($root.'/sites.json', json_encode([['name' => $site, 'document_root' => '/another/app', 'kind' => 'linked']], JSON_THROW_ON_ERROR));
        if ($owned) {
            file_put_contents($root.'/.ai-harness.state.json', json_encode(['yerd_site' => $site, 'yerd_secured' => true], JSON_THROW_ON_ERROR));
        }
        foreach ($owned ? ['setup', 'cleanup', 'php'] : ['setup'] as $action) {
            $process = yerd_harness([$action], $root, $env);
            $process->run();
            expect($process->getExitCode())->toBe(1)->and(yerd_mutations($root))->toBe([]);
        }
    }
});

test('Yerd uses versioned PHP for all PHP tools and forwards arguments and exit status', function (): void {
    [$root, $env] = yerd_fixture();
    write_executable($root.'/bin/composer', "#!/usr/bin/env php\n<?php echo 'composer';");
    foreach (['php', 'artisan', 'test', 'composer'] as $tool) {
        $process = yerd_harness([$tool, 'value with spaces', '$(never executed)'], $root, $env + ['YERD_PHP_EXIT' => '23']);
        $process->run();
        $data = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        expect($process->getExitCode())->toBe(23)->and($data['shim'])->toBe('php8.2')
            ->and(array_slice($data['args'], -2))->toBe(['value with spaces', '$(never executed)']);
    }
});

test('Yerd runtime reads the linked site version or default when no PHP version is configured', function (): void {
    [$root, $env] = yerd_fixture();
    file_put_contents($root.'/.ai-harness.config', "runtime=yerd\nservice_manager=none\nagents=\n");
    $process = yerd_harness(['php', '-v'], $root, $env)->mustRun();
    expect($process->getOutput())->toContain('php8.5');
    file_put_contents($root.'/sites.json', json_encode([['name' => SiteName::forPath($root), 'document_root' => $root, 'kind' => 'linked', 'php' => '8.2']], JSON_THROW_ON_ERROR));
    expect(yerd_harness(['php', '-v'], $root, $env)->mustRun()->getOutput())->toContain('php8.2');
});

test('Yerd setup retries failed secure and unlink actions and recreates missing links', function (): void {
    [$root, $env] = yerd_fixture();
    $first = yerd_harness(['setup'], $root, $env + ['YERD_FAIL' => 'secure']);
    $first->run();
    expect($first->getExitCode())->toBe(19);
    yerd_harness(['setup'], $root, $env)->mustRun();
    $cleanup = yerd_harness(['cleanup'], $root, $env + ['YERD_FAIL' => 'unlink']);
    $cleanup->run();
    expect($cleanup->getExitCode())->toBe(19)->and($root.'/.ai-harness.state.json')->toBeFile();
    file_put_contents($root.'/sites.json', '[]');
    yerd_harness(['setup'], $root, $env)->mustRun();
    expect(count(array_filter(yerd_mutations($root), static fn (array $command): bool => $command[0] === 'link')))->toBe(2);
    yerd_harness(['cleanup'], $root, $env)->mustRun();
    expect($root.'/.ai-harness.state.json')->not->toBeFile();
});

test('Yerd cleanup clears stale ownership for an already removed site', function (): void {
    [$root, $env] = yerd_fixture();
    file_put_contents($root.'/.ai-harness.state.json', json_encode(['yerd_site' => SiteName::forPath($root), 'yerd_secured' => true], JSON_THROW_ON_ERROR));
    yerd_harness(['cleanup'], $root, $env)->mustRun();
    expect(yerd_mutations($root))->toBe([])->and($root.'/.ai-harness.state.json')->not->toBeFile();
});

test('Yerd unsecured setup and HTTPS downgrade use the requested URL scheme', function (): void {
    [$root, $env] = yerd_fixture();
    yerd_harness(['setup'], $root, $env)->mustRun();
    file_put_contents($root.'/.ai-harness.config', "runtime=yerd\nservice_manager=none\nagents=\nsite_secure=false\n");
    yerd_harness(['setup'], $root, $env)->mustRun();
    expect(file_get_contents($root.'/.env'))->toContain('APP_URL=http://'.SiteName::forPath($root).'.test')
        ->and(yerd_mutations($root))->toContain(['unsecure', SiteName::forPath($root)]);
});

test('Yerd malformed or failed JSON never permits mutations', function (): void {
    [$root, $env] = yerd_fixture();
    foreach (['YERD_BAD_JSON', 'YERD_QUERY_FAIL'] as $key) {
        $process = yerd_harness(['setup'], $root, $env + [$key => '1']);
        $process->run();
        expect($process->getExitCode())->toBe(1)->and(yerd_mutations($root))->toBe([]);
    }
});

test('Yerd doctor reports missing Composer and unavailable PHP shims', function (): void {
    [$root, $env] = yerd_fixture();
    $process = yerd_harness(['doctor'], $root, $env);
    $process->run();
    expect($process->getOutput())->toContain('Composer');
    unlink($root.'/bin/php8.2');
    $process = yerd_harness(['doctor'], $root, $env);
    $process->run();
    expect($process->getOutput())->toContain('shim is missing');
});

test('Yerd config validates PHP versions and cloud runtime stays native', function (): void {
    [$root] = yerd_fixture();
    $config = (new ConfigLoader)->load($root);
    expect($config->phpVersion)->toBe('8.2')->and($config->siteSecure)->toBeTrue();
    putenv('AI_HARNESS_ENV=codex-cloud');
    try {
        $factory = new CommandFactory(new ExecutableLocator(overrides: ['php' => PHP_BINARY, 'yerd' => null]));
        expect($factory->runtime($config, 'php', ['-v'], $root))->toBe([PHP_BINARY, '-v']);
    } finally {
        putenv('AI_HARNESS_ENV');
    }
    file_put_contents($root.'/.ai-harness.config', "runtime=yerd\nphp_version=8.2.3\n");
    expect(fn () => (new ConfigLoader)->load($root))->toThrow(ConfigException::class);
});

test('Yerd pruning reports only generated linked names with missing targets', function (): void {
    [$root, $env] = yerd_fixture();
    $missing = $root.'/removed-worktree';
    $name = SiteName::forPath($missing);
    $live = $root.'/live';
    mkdir($live);
    file_put_contents($root.'/sites.json', json_encode([
        ['name' => $name, 'document_root' => $missing, 'kind' => 'linked'],
        ['name' => 'unrelated', 'document_root' => $missing, 'kind' => 'linked'],
        ['name' => SiteName::forPath($live), 'document_root' => $live, 'kind' => 'linked'],
        ['name' => SiteName::forPath($root.'/parked'), 'document_root' => $root.'/parked', 'kind' => 'parked'],
    ], JSON_THROW_ON_ERROR));
    foreach ([['--dry-run'], ['--no-interaction']] as $args) {
        $process = yerd_harness(['prune-yerd', ...$args], $root, $env)->mustRun();
        expect($process->getOutput())->toContain($name, 'No changes')
            ->and($process->getOutput())->not->toContain('unrelated', SiteName::forPath($live))
            ->and(yerd_mutations($root))->toBe([]);
    }
    $old = getenv('YERD_FIXTURE');
    putenv('YERD_FIXTURE='.$root);
    try {
        $command = new PruneYerdCommand(new CommandFactory(new ExecutableLocator(overrides: ['yerd' => $root.'/bin/yerd'])), new ProcessRunner, static function ($stream): bool {
            expect(stream_get_meta_data($stream)['stream_type'])->toBe('MEMORY');

            return true;
        });
        $tester = new CommandTester($command);
        $tester->setInputs(['yes']);
        expect($tester->execute(['--path' => $root], ['interactive' => true]))->toBe(0)
            ->and(yerd_mutations($root))->toBe([['unlink', $name]]);
    } finally {
        putenv($old === false ? 'YERD_FIXTURE' : 'YERD_FIXTURE='.$old);
    }
});

test('Yerd Composer bootstrap resolves the managed phar with checkout PHP', function (): void {
    [$root, $env] = yerd_fixture();
    mkdir($root.'/tools/composer', 0755, true);
    $phar = $root.'/tools/composer/composer.phar';
    file_put_contents($phar, '<?php');
    $old = getenv('YERD_FIXTURE');
    putenv('YERD_FIXTURE='.$root);
    try {
        $factory = new CommandFactory(new ExecutableLocator(overrides: ['yerd' => $root.'/bin/yerd', 'composer' => null]));
        expect($factory->bootstrapComposer((new ConfigLoader)->load($root), $root))
            ->toBe([$root.'/bin/php8.2', $phar, 'install', '--no-interaction', '--prefer-dist']);
    } finally {
        putenv($old === false ? 'YERD_FIXTURE' : 'YERD_FIXTURE='.$old);
    }
});

test('Yerd preserves local overrides and protects its generated APP_URL', function (): void {
    [$root, $env] = yerd_fixture();
    file_put_contents($root.'/.ai-harness.config.local', "local_env.MAIL_PORT=2525\n");
    yerd_harness(['setup'], $root, $env)->mustRun();
    expect(file_get_contents($root.'/.env'))->toContain('MAIL_PORT=2525', 'APP_URL=https://'.SiteName::forPath($root).'.test');
    file_put_contents($root.'/.ai-harness.config.local', "local_env.APP_URL=https://shared.test\n");
    expect(fn () => (new ConfigLoader)->load($root))->toThrow(ConfigException::class, 'local_env.APP_URL is managed');
});

test('Yerd cleanup rechecks ownership after removing HTTPS', function (): void {
    [$root, $env] = yerd_fixture();
    yerd_harness(['setup'], $root, $env)->mustRun();
    $process = yerd_harness(['cleanup'], $root, $env + ['YERD_REASSIGN' => '1']);
    $process->run();
    expect($process->getExitCode())->toBe(1)
        ->and(yerd_mutations($root))->not->toContain(['unlink', SiteName::forPath($root)])
        ->and($root.'/.ai-harness.state.json')->toBeFile();
});

test('Yerd piped confirmations stay report only', function (): void {
    [$root, $env] = yerd_fixture();
    $missing = $root.'/removed-worktree';
    $name = SiteName::forPath($missing);
    file_put_contents($root.'/sites.json', json_encode([['name' => $name, 'document_root' => $missing, 'kind' => 'linked']], JSON_THROW_ON_ERROR));
    $process = yerd_harness(['prune-yerd'], $root, $env);
    $process->setInput("yes\nyes\n");
    $process->mustRun();
    expect($process->getOutput())->toContain($name, 'No changes were made (report only).')
        ->and(yerd_mutations($root))->toBe([]);
});

foreach ([[false, 8080, ':8080'], [true, 8443, ':8443'], [false, 80, ''], [true, 443, '']] as [$secure, $port, $suffix]) {
    test('Yerd APP_URL uses '.($secure ? 'HTTPS' : 'HTTP').' port '.$port, function () use ($secure, $port, $suffix): void {
        [$root, $env] = yerd_fixture();
        file_put_contents($root.'/.ai-harness.config', "runtime=yerd\nservice_manager=none\nagents=\nsite_secure=".($secure ? 'true' : 'false')."\n");
        $report = json_decode((string) file_get_contents($root.'/status.json'), true, flags: JSON_THROW_ON_ERROR);
        $report[$secure ? 'https' : 'http'] = ['requested' => $secure ? 443 : 80, 'bound' => $port];
        file_put_contents($root.'/status.json', json_encode($report, JSON_THROW_ON_ERROR));
        yerd_harness(['setup'], $root, $env)->mustRun();
        $url = ($secure ? 'https' : 'http').'://'.SiteName::forPath($root).'.test'.$suffix;
        expect(file_get_contents($root.'/.env'))->toContain('APP_URL='.$url."\n");
    });
}

foreach ([null, 0, -1, 65536, '8443'] as $port) {
    test('Yerd rejects listener port '.var_export($port, true).' instead of writing unreachable APP_URL', function () use ($port): void {
        [$root, $env] = yerd_fixture();
        $report = json_decode((string) file_get_contents($root.'/status.json'), true, flags: JSON_THROW_ON_ERROR);
        $report['https'] = ['bound' => $port];
        file_put_contents($root.'/status.json', json_encode($report, JSON_THROW_ON_ERROR));
        $process = yerd_harness(['setup'], $root, $env);
        $process->run();
        expect($process->getExitCode())->toBe(1)
            ->and($process->getOutput().$process->getErrorOutput())->toContain('Invalid Yerd HTTPS listener port')
            ->and(file_get_contents($root.'/.env'))->toContain('APP_URL=http://shared.test');
    });
}

test('all process settings work without configuration files and remain effective after global init', function (): void {
    [$root, $env] = yerd_fixture();
    unlink($root.'/.ai-harness.config');
    symlink($root.'/bin/yerd', $root.'/bin/php8.4');
    write_executable($root.'/bin/composer', "#!/usr/bin/env php\n<?php echo 'composer';");
    $env = array_replace($env, [
        'PATH' => $root.'/bin'.PATH_SEPARATOR.package_root().'/bin'.PATH_SEPARATOR.getenv('PATH'),
        'AI_HARNESS_ENV' => 'local',
        'AI_HARNESS_RUNTIME' => 'yerd',
        'AI_HARNESS_PHP_VERSION' => '8.4',
        'AI_HARNESS_SITE_SECURE' => 'false',
        'AI_HARNESS_SERVICE_MANAGER' => 'none',
    ]);
    yerd_harness(['setup'], $root, $env)->mustRun();
    expect($root.'/.ai-harness.config')->not->toBeFile()
        ->and(file_get_contents($root.'/.env'))->toContain('APP_URL=http://'.SiteName::forPath($root).'.test')
        ->and(file_get_contents($root.'/.env'))->not->toContain('AI_HARNESS_', 'php_version=', 'runtime=')
        ->and(file_get_contents($root.'/.env.testing'))->toContain('DB_DATABASE=:memory:')
        ->and(yerd_mutations($root))->toContain(['use', SiteName::forPath($root), '8.4']);
    $doctor = yerd_harness(['doctor'], $root, $env);
    $doctor->run();
    expect($doctor->getOutput())->not->toContain('No project configuration file exists')
        ->and($doctor->getOutput())->toContain('runtime=yerd from AI_HARNESS_RUNTIME', 'php_version=8.4 from AI_HARNESS_PHP_VERSION', 'site_secure=false from AI_HARNESS_SITE_SECURE', 'service_manager=none from AI_HARNESS_SERVICE_MANAGER');

    (new Process(['git', 'init', $root]))->mustRun();
    yerd_harness(['init', '--global'], $root, $env)->mustRun();
    $configContents = file_get_contents($root.'/.ai-harness.config.global');
    expect($configContents)->toContain('runtime=native', 'php_version=', 'site_secure=true')
        ->and($configContents)->not->toContain('runtime=yerd', 'php_version=8.4', 'AI_HARNESS_RUNTIME=')
        ->and(file_get_contents($root.'/AGENTS.override.md'))->toContain('AI_HARNESS_RUNTIME', 'AI_HARNESS_PHP_VERSION', 'AI_HARNESS_SITE_SECURE', 'AI_HARNESS_SERVICE_MANAGER');
    yerd_harness(['doctor'], $root, $env)->mustRun();
    foreach (['php', 'artisan', 'composer', 'test'] as $tool) {
        $process = yerd_harness([$tool, 'argument'], $root, $env)->mustRun();
        expect($process->getOutput())->toContain('php8.4');
    }
    foreach (['up', 'down'] as $action) {
        expect(yerd_harness([$action], $root, $env)->mustRun()->getOutput())->toContain('No Sail containers are configured');
    }
    expect(file_get_contents($root.'/.ai-harness.config.global'))->toBe($configContents);

    // Cleanup follows recorded ownership even when all four settings change.
    $changed = array_replace($env, ['AI_HARNESS_RUNTIME' => 'native', 'AI_HARNESS_PHP_VERSION' => '', 'AI_HARNESS_SITE_SECURE' => 'true', 'AI_HARNESS_SERVICE_MANAGER' => 'sail']);
    file_put_contents($root.'/sites.json', json_encode([['name' => SiteName::forPath($root), 'document_root' => '/another/app', 'kind' => 'linked']], JSON_THROW_ON_ERROR));
    $cleanup = yerd_harness(['cleanup'], $root, $changed);
    $cleanup->run();
    expect($cleanup->getExitCode())->toBe(1)
        ->and(yerd_mutations($root))->not->toContain(['unlink', SiteName::forPath($root)])
        ->and($root.'/.ai-harness.state.json')->toBeFile();
    file_put_contents($root.'/sites.json', json_encode([['name' => SiteName::forPath($root), 'document_root' => $root, 'kind' => 'linked']], JSON_THROW_ON_ERROR));
    yerd_harness(['cleanup'], $root, $changed)->mustRun();
    expect(yerd_mutations($root))->toContain(['unlink', SiteName::forPath($root)])
        ->and($root.'/.ai-harness.state.json')->not->toBeFile();
});

test('service manager process override controls both lifecycle commands', function (): void {
    [$root, $env] = yerd_fixture();
    write_executable($root.'/vendor/bin/sail', "#!/bin/sh\nprintf '%s\\n' \"\$@\" >> sail-log\n");
    $env += ['AI_HARNESS_RUNTIME' => 'native', 'AI_HARNESS_SERVICE_MANAGER' => 'sail', 'AI_HARNESS_ENV' => 'local'];
    foreach (['up', 'down'] as $action) {
        yerd_harness([$action], $root, $env)->mustRun();
    }
    expect(file_get_contents($root.'/sail-log'))->toBe("up\n-d\ndown\n");
});

foreach (['init', 'setup', 'doctor', 'php', 'artisan', 'composer', 'test', 'up', 'down', 'cleanup'] as $action) {
    test("{$action} validates every process override", function () use ($action): void {
        [$root, $env] = yerd_fixture();
        foreach (['AI_HARNESS_RUNTIME' => 'runtime', 'AI_HARNESS_PHP_VERSION' => 'php_version', 'AI_HARNESS_SITE_SECURE' => 'site_secure', 'AI_HARNESS_SERVICE_MANAGER' => 'service_manager'] as $name => $key) {
            $process = yerd_harness([$action], $root, $env + [$name => 'invalid', 'AI_HARNESS_ENV' => 'local']);
            $process->run();
            expect($process->getExitCode())->toBe(1)
                ->and($process->getErrorOutput())->toContain($key.' must be');
        }
        expect(yerd_mutations($root))->toBe([]);
    });
}

test('cloud detection still uses native PHP when local process settings select Yerd', function (): void {
    [$root, $env] = yerd_fixture();
    $env += [
        'AI_HARNESS_RUNTIME' => 'yerd',
        'AI_HARNESS_PHP_VERSION' => '8.4',
        'AI_HARNESS_SITE_SECURE' => 'true',
        'AI_HARNESS_SERVICE_MANAGER' => 'sail',
        'AI_HARNESS_ENV' => 'codex-cloud',
    ];
    // A version query uses native PHP without provisioning any runtime or services.
    $process = yerd_harness(['php', '-v'], $root, $env)->mustRun();
    expect($process->getOutput())->toContain('PHP '.PHP_VERSION)
        ->and(yerd_mutations($root))->toBe([]);
});
