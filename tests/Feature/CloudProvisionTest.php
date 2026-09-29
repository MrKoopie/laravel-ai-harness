<?php

declare(strict_types=1);

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/** @return array{string, array<string, string>} */
function cloud_provision_fixture(): array
{
    $root = temp_directory('cloud-provision');
    mkdir($root.'/bin');
    copy(package_root().'/resources/project/cloud.sh', $root.'/.ai-harness-cloud');
    file_put_contents($root.'/composer.json', '{}');
    file_put_contents($root.'/ubuntu.sources', "Types: deb\nURIs: https://base.invalid\nSuites: noble\nComponents: main\n");
    file_put_contents($root.'/php.list', "deb [signed-by=/usr/share/keyrings/php.gpg] https://php.invalid noble main\n");
    file_put_contents($root.'/extra.sources', "Types: deb\nURIs: https://extra.invalid\nSuites: noble\nComponents: main\n");

    foreach (['id' => '0', 'uname' => 'Linux', 'apt-cache' => '  Depends: php8.5-cli'] as $command => $output) {
        write_executable($root.'/bin/'.$command, "#!/bin/sh\necho '".$output."'\n");
    }

    foreach (['update-alternatives', 'php', 'node', 'npm'] as $command) {
        write_executable($root.'/bin/'.$command, "#!/bin/sh\nprintf '%s\\n' \"\$*\" >> \"\$CLOUD_LOG\"\n");
    }

    // Intercept apt-owned absolute binaries without touching the host's /usr/bin.
    file_put_contents($root.'/bash-env', <<<'BASH'
function /usr/bin/php8.5 {
    if [[ "$1" == /usr/bin/composer ]]; then
        shift
        "$CLOUD_BIN/php8.5" "$CLOUD_BIN/composer" "$@"
    else
        "$CLOUD_BIN/php8.5" "$@"
    fi
}
BASH);

    // Mock only the runtime extension inventory; parse real JSON with real PHP.
    $inventory = 'function extension_loaded(string $name): bool { return $name === "json"; } eval("?>".stream_get_contents(STDIN));';
    write_executable($root.'/bin/php8.5', "#!/bin/sh\n".
        'if [ "$1" = -- ]; then'."\nshift\nexec ".escapeshellarg(PHP_BINARY).
        ' -n -d disable_functions=extension_loaded -r '.escapeshellarg($inventory).' -- "$@"'."\nfi\n".
        'exec '.escapeshellarg(PHP_BINARY).' "$@"'."\n");
    write_executable($root.'/bin/composer', <<<'PHP'
#!/usr/bin/env php
<?php
file_put_contents(getenv('CLOUD_LOG'), implode(' ', array_slice($argv, 1))."\n", FILE_APPEND);

foreach ($argv as $argument) {
    if (str_starts_with($argument, '--working-dir=')) {
        $directory = substr($argument, strlen('--working-dir='));
        file_put_contents(getenv('CLOUD_LOG').'.requirements-directory', $directory);
        copy($directory.'/composer.lock', getenv('CLOUD_LOG').'.lock');
    }
}

if (in_array('--format=json', $argv, true)) {
    $candidates = json_decode(file_get_contents(dirname($directory).'/candidates.json'), true);
    echo json_encode(array_map(fn ($name) => ['name' => $name, 'status' => 'missing'], array_keys($candidates)));
}

exit((int) getenv('PLATFORM_EXIT'));
PHP);

    write_executable($root.'/bin/apt-get', <<<'BASH'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "$CLOUD_LOG"
for argument in "$@"; do
    case "$argument" in
        Dir::Etc::sourcelist=*) cat "${argument#*=}" >> "$CLOUD_LOG.sources" ;;
        Dir::Etc::sourceparts=*)
            directory="${argument#*=}"
            if [[ -d "$directory" ]]; then
                printf '%s' "$directory" > "$CLOUD_LOG.directory"
                for source in "$directory"/*; do
                    cat "$source" >> "$CLOUD_LOG.sources"
                    printf '%s\n' "$source" >> "$CLOUD_LOG.files"
                done
            fi
            ;;
    esac
done
case "$*" in
    *php8.5-imagick*) exit "${EXTENSION_APT_EXIT:-0}" ;;
esac
exit "${APT_EXIT:-0}"
BASH);

    return [$root, [
        'AI_HARNESS_ENV' => 'claude-cloud',
        'AI_HARNESS_APT_SOURCE_LIST' => $root.'/ubuntu.sources',
        'AI_HARNESS_APT_EXTRA_SOURCES' => '',
        'AI_HARNESS_PHP_EXTENSIONS' => '',
        'AI_HARNESS_PHP_VERSION' => '8.5',
        'AI_HARNESS_COMPOSER_JSON' => '',
        'PATH' => $root.'/bin'.PATH_SEPARATOR.getenv('PATH'),
        'CLOUD_LOG' => $root.'/commands',
        'CLOUD_BIN' => $root.'/bin',
        'BASH_ENV' => $root.'/bash-env',
        'APT_EXIT' => '0',
        'PLATFORM_EXIT' => '0',
        'EXTENSION_APT_EXIT' => '0',
    ]];
}

foreach (['success' => '0', 'apt failure' => '42'] as $scenario => $exit) {
    test('cloud provision includes selected extra sources alongside the base and cleans temporary files: '.$scenario, function () use ($exit): void {
        [$root, $environment] = cloud_provision_fixture();
        $environment['AI_HARNESS_APT_EXTRA_SOURCES'] = $root.'/php.list:'.$root.'/extra.sources';
        $environment['APT_EXIT'] = $exit;
        $process = new Process(['bash', $root.'/.ai-harness-cloud', 'provision'], $root, $environment);
        $process->run();

        expect($process->getExitCode())->toBe((int) $exit)
            ->and(file_get_contents($root.'/commands.sources'))->toContain('https://base.invalid', 'https://php.invalid', 'https://extra.invalid', 'signed-by=/usr/share/keyrings/php.gpg')
            ->and(file_get_contents($root.'/commands.files'))->toContain('.list', '.sources');

        $directory = file_get_contents($root.'/commands.directory');

        if ($directory === false) {
            throw new RuntimeException('Missing temporary source directory log.');
        }

        expect(is_dir($directory))->toBeFalse()
            ->and(file_get_contents($root.'/php.list'))->toContain('https://php.invalid');

        if ($exit === '0') {
            expect(file_get_contents($root.'/commands'))->toContain('php8.5-cli', 'mysql-server', '--set php /usr/bin/php8.5');
        }
    });
}

test('cloud provision installs extra extensions for the selected PHP without removing defaults', function (): void {
    [$root, $environment] = cloud_provision_fixture();
    $environment['AI_HARNESS_PHP_EXTENSIONS'] = 'imagick,soap';
    $process = new Process(['bash', $root.'/.ai-harness-cloud', 'provision'], $root, $environment);
    $process->mustRun();

    expect(file_get_contents($root.'/commands'))->toContain('php8.5-imagick', 'php8.5-soap', 'php8.5-mysql', 'php8.5-redis', 'Dir::Etc::sourceparts=-');
});

foreach ([
    'relative source' => ['AI_HARNESS_APT_EXTRA_SOURCES', 'php.list'],
    'missing source' => ['AI_HARNESS_APT_EXTRA_SOURCES', '{root}/missing.list'],
    'source directory' => ['AI_HARNESS_APT_EXTRA_SOURCES', '{root}/bin'],
    'wrong source format' => ['AI_HARNESS_APT_EXTRA_SOURCES', '{root}/bin/php'],
    'empty source entry' => ['AI_HARNESS_APT_EXTRA_SOURCES', '{root}/php.list::{root}/extra.sources'],
    'trailing source separator' => ['AI_HARNESS_APT_EXTRA_SOURCES', '{root}/php.list:'],
    'extension option' => ['AI_HARNESS_PHP_EXTENSIONS', '--allow-unauthenticated'],
    'extension shell syntax' => ['AI_HARNESS_PHP_EXTENSIONS', 'soap;id'],
    'extension package pattern' => ['AI_HARNESS_PHP_EXTENSIONS', 'soap*'],
    'empty extension entry' => ['AI_HARNESS_PHP_EXTENSIONS', 'soap,,imagick'],
    'trailing extension separator' => ['AI_HARNESS_PHP_EXTENSIONS', 'soap,'],
] as $scenario => [$key, $value]) {
    test('cloud provision rejects invalid extra configuration before running apt: '.$scenario, function () use ($key, $value): void {
        [$root, $environment] = cloud_provision_fixture();
        $environment[$key] = str_replace('{root}', $root, $value);
        $process = new Process(['bash', $root.'/.ai-harness-cloud', 'provision'], $root, $environment);
        $process->run();

        expect($process->getExitCode())->toBe(1)
            ->and($process->getErrorOutput())->toContain($key)
            ->and($root.'/commands')->not->toBeFile();
    });
}

test('cloud provision discovers PHP with extra sources and installs extensions through sudo', function (): void {
    [$root, $environment] = cloud_provision_fixture();
    $environment['AI_HARNESS_PHP_VERSION'] = '';
    $environment['AI_HARNESS_APT_EXTRA_SOURCES'] = $root.'/php.list';
    $environment['AI_HARNESS_PHP_EXTENSIONS'] = 'imagick,soap';
    write_executable($root.'/bin/id', "#!/bin/sh\necho 1000\n");
    write_executable($root.'/bin/sudo', <<<'BASH'
#!/usr/bin/env bash
[[ "$1" == -n ]] || exit 90
shift
printf 'sudo\n' >> "$CLOUD_LOG"
exec "$@"
BASH);
    write_executable($root.'/bin/apt-cache', <<<'BASH'
#!/usr/bin/env bash
printf '%s\n' "$*" > "$CLOUD_LOG.discovery"
echo '  Depends: php8.5-cli'
BASH);
    $process = new Process(['bash', $root.'/.ai-harness-cloud', 'provision'], $root, $environment);
    $process->mustRun();

    $directory = file_get_contents($root.'/commands.directory');
    expect(file_get_contents($root.'/commands.discovery'))->toContain('Dir::Etc::sourcelist='.$root.'/ubuntu.sources', 'Dir::Etc::sourceparts='.$directory, 'depends php-cli')
        ->and(file_get_contents($root.'/commands'))->toContain('sudo', 'php8.5-imagick', 'php8.5-soap', '--set php /usr/bin/php8.5');
});

test('cloud provision detects root development and locked extension requirements', function (): void {
    [$root, $environment] = cloud_provision_fixture();
    file_put_contents($root.'/composer.json', json_encode([
        'require' => ['ext-imagick' => '*', 'ext-json' => '*', 'ext-pdo_mysql' => '*'],
        'require-dev' => ['ext-soap' => '*', 'ext-dom' => '*'],
    ], JSON_THROW_ON_ERROR));
    file_put_contents($root.'/composer.lock', json_encode([
        'packages' => [['name' => 'test/runtime', 'version' => '1.0.0', 'require' => ['ext-gd' => '*']]],
        'packages-dev' => [['name' => 'test/dev', 'version' => '1.0.0', 'require' => ['ext-exif' => '*']]],
    ], JSON_THROW_ON_ERROR));
    $process = new Process(['bash', $root.'/.ai-harness-cloud', 'provision'], $root, $environment);
    $process->mustRun();

    expect(file_get_contents($root.'/commands'))
        ->toContain('php8.5-imagick', 'php8.5-soap', 'php8.5-gd', 'php8.5-common', 'check-platform-reqs', '--lock')
        ->and(file_get_contents($root.'/commands'))->not->toContain('php8.5-json', 'php8.5-pdo_mysql', 'php8.5-dom', 'php8.5-exif');
});

foreach (['relative', 'absolute'] as $pathType) {
    test('cloud provision reads a '.$pathType.' custom manifest and its adjacent lock', function () use ($pathType): void {
        [$root, $environment] = cloud_provision_fixture();
        mkdir($root.'/nested app');
        file_put_contents($root.'/composer.json', '{invalid default ignored');
        file_put_contents($root.'/nested app/custom.json', '{"require":{"ext-imagick":"*"}}');
        file_put_contents($root.'/nested app/composer.lock', '{"packages":[{"name":"test/locked","version":"1.0.0","require":{"ext-soap":"*"}}]}');
        $environment['AI_HARNESS_COMPOSER_JSON'] = ($pathType === 'absolute' ? $root.'/' : '').'nested app/custom.json';
        $process = new Process(['bash', $root.'/.ai-harness-cloud', 'provision'], sys_get_temp_dir(), $environment);
        $process->mustRun();

        expect(file_get_contents($root.'/commands'))->toContain('php8.5-imagick', 'php8.5-soap');
    });
}

foreach ([
    'missing manifest' => ['missing.json', null, null],
    'invalid manifest' => ['composer.json', '{invalid', null],
    'invalid requirement shape' => ['composer.json', '{"require":"ext-imagick"}', null],
    'unsafe extension name' => ['composer.json', '{"require":{"ext-imagick*":"*"}}', null],
    'invalid lock' => ['composer.json', '{}', '{invalid'],
] as $scenario => [$manifest, $contents, $lock]) {
    test('cloud provision rejects '.$scenario, function () use ($manifest, $contents, $lock): void {
        [$root, $environment] = cloud_provision_fixture();
        $environment['AI_HARNESS_COMPOSER_JSON'] = $manifest;

        if ($contents !== null) {
            file_put_contents($root.'/'.$manifest, $contents);
        }

        if ($lock !== null) {
            file_put_contents($root.'/composer.lock', $lock);
        }

        $process = new Process(['bash', $root.'/.ai-harness-cloud', 'provision'], $root, $environment);
        $process->run();

        expect($process->isSuccessful())->toBeFalse()
            ->and($process->getErrorOutput())->toContain('Composer requirements');
    });
}

test('cloud provision propagates dependency installation failures', function (): void {
    [$root, $environment] = cloud_provision_fixture();
    file_put_contents($root.'/composer.json', '{"require":{"ext-imagick":"*"}}');
    $environment['EXTENSION_APT_EXIT'] = '44';
    $process = new Process(['bash', $root.'/.ai-harness-cloud', 'provision'], $root, $environment);
    $process->run();

    expect($process->getExitCode())->toBe(44);
});

test('cloud provision propagates platform validation failures and cleans temporary files', function (): void {
    [$root, $environment] = cloud_provision_fixture();
    $environment['PLATFORM_EXIT'] = '2';
    $process = new Process(['bash', $root.'/.ai-harness-cloud', 'provision'], $root, $environment);
    $process->run();
    $directory = file_get_contents($root.'/commands.requirements-directory');

    expect($process->getExitCode())->toBe(2)
        ->and($directory)->toBeString()
        ->and(is_dir((string) $directory))->toBeFalse();
});

$platformCheck = function (string $manifest, ?string $lock, bool $success, ?string $launcher = null): void {
    [$root, $environment] = cloud_provision_fixture();
    $composer = $launcher ?? (new ExecutableFinder)->find('composer');

    if ($composer === null) {
        throw new RuntimeException('Composer is required for cloud provisioning integration tests.');
    }

    // Execute the discovered entrypoint normally: it may be a shell shim.
    $environment['REAL_COMPOSER'] = $composer;
    $environment['REAL_PATH'] = (string) getenv('PATH');
    file_put_contents($root.'/bash-env', <<<'BASH'
function /usr/bin/php8.5 {
    if [[ "$1" == /usr/bin/composer ]]; then
        shift
        PATH="$REAL_PATH" "$REAL_COMPOSER" "$@"
    else
        "$CLOUD_BIN/php8.5" "$@"
    fi
}
BASH);

    file_put_contents($root.'/composer.json', $manifest);

    if ($lock !== null) {
        file_put_contents($root.'/composer.lock', $lock);
    }

    $process = new Process(['bash', $root.'/.ai-harness-cloud', 'provision'], $root, $environment);
    $process->run();

    expect($process->isSuccessful())->toBe($success, $process->getOutput().$process->getErrorOutput());

    if (! $success) {
        expect($process->getOutput())->toContain('failed');
    }

    if ($success && str_contains($manifest, 'ext-harness-provider')) {
        expect(file_get_contents($root.'/commands'))->not->toContain('php8.5-harness-provider');
    }

    expect(file_get_contents($root.'/composer.json'))->toBe($manifest);

    if ($lock !== null) {
        expect(file_get_contents($root.'/composer.lock'))->toBe($lock);
    }
};

foreach ([
    'root provider' => ['{"require":{"ext-harness-provider":"^2"},"provide":{"ext-harness-provider":"2.1"}}', null, true],
    'root replacement self version' => ['{"version":"2.1.0","require":{"ext-harness-provider":"^2"},"replace":{"ext-harness-provider":"self.version"}}', null, true],
    'locked provider' => ['{"require":{"ext-harness-provider":"^2"}}', '{"packages":[{"name":"test/provider","version":"2.1.0","provide":{"ext-harness-provider":"self.version"}}]}', true],
    'locked dev replacement' => ['{"require-dev":{"ext-harness-provider":"^2"}}', '{"packages-dev":[{"name":"test/provider","version":"2.1.0","replace":{"ext-harness-provider":"self.version"}}]}', true],
    'incompatible provider' => ['{"require":{"ext-harness-provider":"^3"},"provide":{"ext-harness-provider":"2.1"}}', null, false],
    'compatible PHP' => ['{"require":{"php":">=8.2","ext-json":"*"}}', null, true],
    'incompatible PHP despite emulation' => ['{"require":{"php":">=99"},"config":{"platform":{"php":"99.0.0"}}}', null, false],
    'incompatible development extension' => ['{"require-dev":{"ext-json":">=99"}}', null, false],
    'incompatible locked development dependency' => ['{}', '{"packages-dev":[{"name":"test/dev","version":"1.0.0","require":{"php":">=99"}}]}', false],
    'incompatible locked dependency' => ['{}', '{"packages":[{"name":"test/locked","version":"1.0.0","require":{"php":">=99"}}]}', false],
] as $scenario => [$platformManifest, $platformLock, $platformSuccess]) {
    test('cloud provision validates with real Composer: '.$scenario, fn () => $platformCheck($platformManifest, $platformLock, $platformSuccess));
}

test('cloud provision ignores provider PHP and Composer shims', function (): void {
    [$root, $environment] = cloud_provision_fixture();
    mkdir($root.'/shims');

    foreach (['php', 'php8.5', 'composer'] as $command) {
        write_executable($root.'/shims/'.$command, "#!/bin/sh\nexit 98\n");
    }

    $environment['PATH'] = $root.'/shims'.PATH_SEPARATOR.$environment['PATH'];
    $environment['PLATFORM_EXIT'] = '2';
    $process = new Process(['bash', $root.'/.ai-harness-cloud', 'provision'], $root, $environment);
    $process->run();

    expect($process->getExitCode())->toBe(2)
        ->and(file_get_contents($root.'/commands'))->toContain('check-platform-reqs');
});

foreach (['ext-mysqlnd' => 'mysql', 'ext-pdo_firebird' => 'interbase'] as $requirement => $package) {
    test('cloud provision maps '.$requirement.' to its Debian package', function () use ($requirement, $package): void {
        [$root, $environment] = cloud_provision_fixture();
        file_put_contents($root.'/composer.json', json_encode(['require' => [$requirement => '*']], JSON_THROW_ON_ERROR));
        $process = new Process(['bash', $root.'/.ai-harness-cloud', 'provision'], $root, $environment);
        $process->mustRun();
        $commands = (string) file_get_contents($root.'/commands');

        expect($commands)->not->toContain('php8.5-mysqlnd');
        expect($commands)->not->toContain('php8.5-pdo-firebird');
        expect($process->getOutput())->toContain('Installing Composer PHP requirements: php8.5-'.$package."\n");
    });
}

test('real Composer test harness accepts a shell launcher', function () use ($platformCheck): void {
    $root = temp_directory('composer-launcher');
    $composer = (new ExecutableFinder)->find('composer');

    if ($composer === null) {
        throw new RuntimeException('Composer is required.');
    }

    write_executable($root.'/composer', "#!/bin/sh\nexec ".escapeshellarg($composer).' "$@"'."\n");
    $platformCheck('{"require":{"php":">=99"}}', null, false, $root.'/composer');
});

test('cloud provision rejects malformed Composer preflight output before extension installation', function (): void {
    [$root, $environment] = cloud_provision_fixture();
    file_put_contents($root.'/composer.json', '{"require":{"ext-imagick":"*"}}');
    write_executable($root.'/bin/composer', "#!/usr/bin/env php\n<?php echo 'not JSON';\n");
    $process = new Process(['bash', $root.'/.ai-harness-cloud', 'provision'], $root, $environment);
    $process->run();

    expect($process->isSuccessful())->toBeFalse()
        ->and($process->getErrorOutput())->toContain('Composer requirements:')
        ->and(file_get_contents($root.'/commands'))->not->toContain('php8.5-imagick');
});
