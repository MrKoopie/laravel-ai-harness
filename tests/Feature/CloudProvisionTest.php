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
                    sed -n 's/^Signed-By: //p' "$source" | while read -r keyring; do
                        [[ -f "$keyring" ]] || continue
                        printf '%s %s %s: %s\n' "$keyring" "$(stat -c %a "$keyring")" "$(stat -c %a "$(dirname "$keyring")")" "$(cat "$keyring")" >> "$CLOUD_LOG.keyrings"
                    done
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
        'AI_HARNESS_PHP_REPOSITORY' => 'none',
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
    'unknown PHP repository' => ['AI_HARNESS_PHP_REPOSITORY', 'launchpad'],
    'missing base source' => ['AI_HARNESS_APT_SOURCE_LIST', '{root}/missing.sources'],
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

/** @return array{string, array<string, string>} */
function cloud_php_repository_fixture(string $distribution = 'ubuntu'): array
{
    [$root, $environment] = cloud_provision_fixture();
    mkdir($root.'/apt-sources');
    mkdir($root.'/keyrings-image');
    file_put_contents($root.'/os-release', "ID={$distribution}\nVERSION_CODENAME=noble\n");

    // The gpg stub reports the sury key for a keyring that names packages.sury.org.
    foreach (['deb.sury.org-php' => 'packages.sury.org', 'ondrej' => 'ondrej/php', 'sury' => 'packages.sury.org'] as $keyring => $origin) {
        file_put_contents($root.'/keyrings-image/'.$keyring.'.gpg', "image key from {$origin}\n");
    }

    // Answer each probe unless its URL contains a host from CURL_DOWN. CURL_OLD
    // simulates curl before 7.71, which lacks --retry-all-errors.
    write_executable($root.'/bin/curl', <<<'BASH'
#!/usr/bin/env bash
if [[ "$1" == --help ]]; then
    [[ -n "${CURL_OLD:-}" ]] || printf ' --retry-all-errors  Retry all errors\n'
    exit 0
fi
printf '%s\n' "$*" >> "$CLOUD_LOG.curl"
output=''
url=''
while (($#)); do
    case "$1" in
        -o) output="$2"; shift ;;
        http*) url="$1" ;;
    esac
    shift
done
for host in ${CURL_DOWN:-}; do
    [[ "$url" == *"$host"* ]] && exit 22
done
for host in ${CURL_TLS_FAIL:-}; do
    [[ "$url" == *"$host"* ]] && exit 60
done
if [[ -n "$output" ]]; then
    printf 'key from %s\n' "$url" > "$output"
else
    printf 'key from %s\n' "$url"
fi
BASH);
    write_executable($root.'/bin/gpg', <<<'BASH'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "$CLOUD_LOG.gpg"
case "$*" in
    *--dearmor*) cat > "${*: -1}" ;;
    *--show-keys*)
        if grep -q packages.sury.org "${*: -1}"; then
            fingerprint="${GPG_SURY_FINGERPRINT:-15058500A0235D97F5D10063B188E2B695BD4743}"
        else
            fingerprint="${GPG_FINGERPRINT:-14AA40EC0831756756D7F66C4F4EA0AAE5267A6C}"
        fi
        validity=-
        grep -q packages.sury.org "${*: -1}" && validity="${GPG_SURY_VALIDITY:--}"
        printf 'pub:%s:3072:1:%s:::::::::\nfpr:::::::::%s:\n' "$validity" "${fingerprint: -16}" "$fingerprint"
        [[ -z "${GPG_EXTRA_KEY:-}" ]] || printf 'pub:-:3072:1:CCCCCCCCCCCCCCCC:::::::::\nfpr:::::::::%s:\n' "$(printf 'C%.0s' {1..40})"
        ;;
esac
BASH);
    write_executable($root.'/bin/install', <<<'BASH'
#!/usr/bin/env bash
printf 'install %s\n' "$*" >> "$CLOUD_LOG"
exec /usr/bin/install "$@"
BASH);

    return [$root, array_merge($environment, [
        'AI_HARNESS_PHP_REPOSITORY' => 'auto',
        'AI_HARNESS_APT_SOURCES_DIR' => $root.'/apt-sources',
        'AI_HARNESS_APT_KEYRINGS_DIR' => $root.'/keyrings',
        'AI_HARNESS_OS_RELEASE' => $root.'/os-release',
        'CURL_DOWN' => '',
    ])];
}

test('cloud provision registers the sury PHP repository first and retries apt downloads', function (): void {
    [$root, $environment] = cloud_php_repository_fixture();
    $process = new Process(['bash', $root.'/.ai-harness-cloud', 'provision'], $root, $environment);
    $process->mustRun();

    $source = (string) file_get_contents($root.'/apt-sources/ai-harness-php.sources');
    $curl = (string) file_get_contents($root.'/commands.curl');

    expect($source)->toBe("Types: deb\nURIs: https://packages.sury.org/php\nSuites: noble\nComponents: main\nSigned-By: {$root}/keyrings/ai-harness-php.gpg\n")
        ->and(file_get_contents($root.'/keyrings/ai-harness-php.gpg'))->toBe("key from https://packages.sury.org/php/apt.gpg\n")
        ->and($curl)->toContain('--retry 3', '--retry-all-errors', 'https://packages.sury.org/php/dists/noble/Release')
        ->and($curl)->not->toContain('launchpad')
        ->and($curl)->not->toContain('keyserver')
        ->and(file_get_contents($root.'/commands.sources'))->toContain('https://base.invalid', 'URIs: https://packages.sury.org/php')
        ->and(file_get_contents($root.'/commands'))->toContain('Acquire::Retries=5', 'php8.5-cli')
        ->and(file_get_contents($root.'/commands.gpg'))->toContain('--show-keys');

    foreach (explode("\n", trim((string) file_get_contents($root.'/commands'))) as $line) {
        if (str_contains($line, 'update') || str_contains($line, 'install -y')) {
            expect($line)->toContain('Acquire::Retries=5');
        }
    }
});

test('cloud provision falls back to the Launchpad content host when sury does not answer', function (): void {
    [$root, $environment] = cloud_php_repository_fixture();
    $environment['CURL_DOWN'] = 'packages.sury.org';
    $process = new Process(['bash', $root.'/.ai-harness-cloud', 'provision'], $root, $environment);
    $process->mustRun();
    $curl = (string) file_get_contents($root.'/commands.curl');

    expect(file_get_contents($root.'/apt-sources/ai-harness-php.sources'))->toContain('URIs: https://ppa.launchpadcontent.net/ondrej/php/ubuntu', 'Suites: noble')
        ->and($curl)->toContain(
            'https://packages.sury.org/php/dists/noble/Release',
            'https://ppa.launchpadcontent.net/ondrej/php/ubuntu/dists/noble/Release',
            'search=0x14AA40EC0831756756D7F66C4F4EA0AAE5267A6C',
        )
        ->and($curl)->not->toContain('ppa.launchpad.net')
        ->and(file_get_contents($root.'/commands.gpg'))->toContain('--dearmor', '--show-keys')
        ->and($process->getErrorOutput())->toContain('https://packages.sury.org/php does not answer');
});

test('cloud provision rejects a Launchpad key with a different fingerprint', function (): void {
    [$root, $environment] = cloud_php_repository_fixture();
    $environment['CURL_DOWN'] = 'packages.sury.org';
    $environment['GPG_FINGERPRINT'] = str_repeat('A', 40);
    $process = new Process(['bash', $root.'/.ai-harness-cloud', 'provision'], $root, $environment);
    $process->run();

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('fingerprint')
        ->and($root.'/apt-sources/ai-harness-php.sources')->not->toBeFile()
        ->and($root.'/commands')->not->toBeFile();
});

test('cloud provision falls back to Launchpad when the sury key has a different fingerprint', function (): void {
    [$root, $environment] = cloud_php_repository_fixture();
    $environment['GPG_SURY_FINGERPRINT'] = str_repeat('B', 40);
    $process = new Process(['bash', $root.'/.ai-harness-cloud', 'provision'], $root, $environment);
    $process->mustRun();

    expect(file_get_contents($root.'/apt-sources/ai-harness-php.sources'))->toContain('URIs: https://ppa.launchpadcontent.net/ondrej/php/ubuntu')
        ->and(file_get_contents($root.'/keyrings/ai-harness-php.gpg'))->toContain('keyserver.ubuntu.com')
        ->and($process->getErrorOutput())->toContain('The sury signing key is not one valid key with fingerprint 15058500A0235D97F5D10063B188E2B695BD4743');
});

foreach (['expired' => 'e', 'revoked' => 'r'] as $state => $validity) {
    test("cloud provision falls back to Launchpad when the sury key is {$state}", function () use ($validity): void {
        [$root, $environment] = cloud_php_repository_fixture();
        $environment['GPG_SURY_VALIDITY'] = $validity;
        $process = new Process(['bash', $root.'/.ai-harness-cloud', 'provision'], $root, $environment);
        $process->mustRun();

        expect(file_get_contents($root.'/apt-sources/ai-harness-php.sources'))->toContain('URIs: https://ppa.launchpadcontent.net/ondrej/php/ubuntu')
            ->and($process->getErrorOutput())->toContain('The sury signing key is not one valid key with fingerprint 15058500A0235D97F5D10063B188E2B695BD4743');
    });
}

test('cloud provision stops on Debian when the sury key has a different fingerprint', function (): void {
    [$root, $environment] = cloud_php_repository_fixture('debian');
    $environment['AI_HARNESS_PHP_REPOSITORY'] = 'sury';
    $environment['GPG_SURY_FINGERPRINT'] = str_repeat('B', 40);
    $process = new Process(['bash', $root.'/.ai-harness-cloud', 'provision'], $root, $environment);
    $process->run();

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('The sury signing key is not one valid key with fingerprint')
        ->and($root.'/apt-sources/ai-harness-php.sources')->not->toBeFile()
        ->and($root.'/commands')->not->toBeFile();
});

test('cloud provision does not use Launchpad on Debian', function (): void {
    [$root, $environment] = cloud_php_repository_fixture('debian');
    $environment['AI_HARNESS_PHP_REPOSITORY'] = 'sury';
    $environment['CURL_DOWN'] = 'packages.sury.org';
    $process = new Process(['bash', $root.'/.ai-harness-cloud', 'provision'], $root, $environment);
    $process->run();

    expect($process->getExitCode())->toBe(1)
        ->and(file_get_contents($root.'/commands.curl'))->not->toContain('launchpad')
        ->and($process->getErrorOutput())->toContain('allow packages.sury.org');
});

test('cloud provision stops when a required PHP repository does not answer', function (): void {
    [$root, $environment] = cloud_php_repository_fixture();
    $environment['AI_HARNESS_PHP_REPOSITORY'] = 'sury';
    $environment['CURL_DOWN'] = 'packages.sury.org launchpadcontent.net';
    $process = new Process(['bash', $root.'/.ai-harness-cloud', 'provision'], $root, $environment);
    $process->run();

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('allow packages.sury.org in the network policy')
        ->and($root.'/apt-sources/ai-harness-php.sources')->not->toBeFile()
        ->and($root.'/commands')->not->toBeFile();
});

test('cloud provision continues with the configured sources when an automatic PHP repository does not answer', function (): void {
    [$root, $environment] = cloud_php_repository_fixture();
    $environment['CURL_DOWN'] = 'packages.sury.org launchpadcontent.net';
    $process = new Process(['bash', $root.'/.ai-harness-cloud', 'provision'], $root, $environment);
    $process->mustRun();

    expect($process->getErrorOutput())->toContain('Continuing without a PHP repository')
        ->and($root.'/apt-sources/ai-harness-php.sources')->not->toBeFile()
        ->and(file_get_contents($root.'/commands'))->toContain('php8.5-cli', 'Dir::Etc::sourceparts=-');
});

test('cloud provision adds no PHP repository without a selected PHP version', function (): void {
    [$root, $environment] = cloud_php_repository_fixture();
    $environment['AI_HARNESS_PHP_VERSION'] = '';
    $process = new Process(['bash', $root.'/.ai-harness-cloud', 'provision'], $root, $environment);
    $process->mustRun();

    expect($root.'/commands.curl')->not->toBeFile()
        ->and($root.'/apt-sources/ai-harness-php.sources')->not->toBeFile();
});

foreach (['selected extra source' => 'extra', 'registered image source' => 'image'] as $scenario => $location) {
    test('cloud provision reuses an existing sury source: '.$scenario, function () use ($location): void {
        [$root, $environment] = cloud_php_repository_fixture();
        $existing = $location === 'extra' ? $root.'/sury.list' : $root.'/apt-sources/php.list';
        file_put_contents($existing, "deb [signed-by={$root}/keyrings-image/deb.sury.org-php.gpg] https://packages.sury.org/php/ noble main\n");

        if ($location === 'extra') {
            $environment['AI_HARNESS_APT_EXTRA_SOURCES'] = $existing;
        }

        $process = new Process(['bash', $root.'/.ai-harness-cloud', 'provision'], $root, $environment);
        $process->mustRun();

        $curl = is_file($root.'/commands.curl') ? (string) file_get_contents($root.'/commands.curl') : '';

        // A selected source is the user's choice; an image source is used only when sury answers.
        expect($curl)->toBe($location === 'extra' ? '' : "-fsSL --retry 3 --connect-timeout 10 --max-time 60 --retry-all-errors -o /dev/null https://packages.sury.org/php/dists/noble/Release\n")
            ->and($root.'/apt-sources/ai-harness-php.sources')->not->toBeFile()
            ->and(substr_count((string) file_get_contents($root.'/commands.sources'), 'packages.sury.org'))->toBeGreaterThan(0);
    });
}

foreach ([
    'commented list entry' => ['php.list', "# deb https://packages.sury.org/php/ noble main\n"],
    'disabled deb822 stanza' => ['php.sources', "Types: deb\nURIs: https://packages.sury.org/php/\nSuites: noble\nComponents: main\nEnabled: no\n"],
    'other URI with a sury comment' => ['php.list', "deb https://mirror.invalid/php noble main # packages.sury.org/php\n"],
    'other URI with a sury path' => ['php.sources', "Types: deb\nURIs: https://mirror.invalid/packages.sury.org/php/\nSuites: noble\nComponents: main\n"],
    'commented deb822 field' => ['php.sources', "Types: deb\n# URIs: https://packages.sury.org/php/\nURIs: https://other.invalid/\nSuites: noble\nComponents: main\n"],
    'folded disabled deb822 stanza' => ['php.sources', "Types: deb\nURIs: https://packages.sury.org/php/\nSuites: noble\nComponents: main\nSigned-By: {root}/keyrings-image/sury.gpg\nEnabled:\n no\n"],
    'URI with other characters for the dots' => ['php.list', "deb [signed-by={root}/keyrings-image/sury.gpg] https://packagesXsuryYorg/php/ noble main\n"],
] as $scenario => [$file, $contents]) {
    test('cloud provision registers the sury PHP repository when the existing source is inactive: '.$scenario, function () use ($file, $contents): void {
        [$root, $environment] = cloud_php_repository_fixture();
        file_put_contents($root.'/apt-sources/'.$file, str_replace('{root}', $root, $contents));
        $process = new Process(['bash', $root.'/.ai-harness-cloud', 'provision'], $root, $environment);
        $process->mustRun();

        expect(file_get_contents($root.'/apt-sources/ai-harness-php.sources'))->toContain('URIs: https://packages.sury.org/php')
            ->and(file_get_contents($root.'/commands.sources'))->toContain('Signed-By: '.$root.'/keyrings/ai-harness-php.gpg');
    });
}

test('cloud provision reuses an enabled deb822 sury stanza next to a disabled one', function (): void {
    [$root, $environment] = cloud_php_repository_fixture();
    file_put_contents($root.'/apt-sources/php.sources', "Types: deb\nURIs: https://packages.sury.org/php/\nSuites: noble\nComponents: main\nEnabled: no\n\nTypes: deb\nURIs:\n https://mirror.invalid/\n https://packages.sury.org/php/\nSuites: noble jammy\nComponents: main\nSigned-By: {$root}/keyrings-image/sury.gpg\n");
    $process = new Process(['bash', $root.'/.ai-harness-cloud', 'provision'], $root, $environment);
    $process->mustRun();

    expect(file_get_contents($root.'/commands.curl'))->not->toContain('apt.gpg')
        ->and($root.'/apt-sources/ai-harness-php.sources')->not->toBeFile()
        ->and(file_get_contents($root.'/commands.sources'))->toContain("URIs: https://packages.sury.org/php\nSuites: noble\n")
        ->and(file_get_contents($root.'/commands.sources'))->not->toContain('mirror.invalid')
        ->and(file_get_contents($root.'/commands.sources'))->not->toContain('jammy');
});

foreach ([
    'list entry' => ['php.list', "deb [arch=ai-harness-other trusted=yes signed-by={root}/keyrings-image/sury.gpg] https://packages.sury.org/php/ noble universe\n"],
    'deb822 stanza' => ['php.sources', "Types: deb\nURIs: https://packages.sury.org/php/\nSuites: noble jammy\nComponents: universe\nArchitectures-Remove: amd64\nTrusted: yes\nSigned-By: {root}/keyrings-image/sury.gpg\n"],
    'list entry with an upper-case scheme and host' => ['php.list', "deb [signed-by={root}/keyrings-image/sury.gpg] HTTPS://PACKAGES.SURY.ORG/php noble main\n"],
    'deb822 stanza with an encoded slash' => ['php.sources', "Types: deb\nURIs: https://packages.sury.org/php%2F\nSuites: noble\nComponents: main\nSigned-By: {root}/keyrings-image/sury.gpg\n"],
] as $scenario => [$file, $contents]) {
    test('cloud provision reuses only the verified keyring of a found sury source: '.$scenario, function () use ($file, $contents): void {
        [$root, $environment] = cloud_php_repository_fixture();
        file_put_contents($root.'/apt-sources/'.$file, str_replace('{root}', $root, $contents));
        $process = new Process(['bash', $root.'/.ai-harness-cloud', 'provision'], $root, $environment);
        $process->mustRun();
        $sources = (string) file_get_contents($root.'/commands.sources');

        expect(file_get_contents($root.'/commands.curl'))->not->toContain('apt.gpg')
            ->and($root.'/apt-sources/ai-harness-php.sources')->not->toBeFile()
            ->and($sources)->toMatch('#'.preg_quote("URIs: https://packages.sury.org/php\nSuites: noble\nComponents: main\nSigned-By: ", '#').'\S+/php-repository-reused[.]gpg\n#')
            ->and(file_get_contents($root.'/commands.keyrings'))->toMatch('#/php-repository-reused[.]gpg 644 755: image key from packages[.]sury[.]org\n#')
            ->and($sources)->not->toContain('jammy')
            ->and($sources)->not->toContain('universe')
            ->and(strtolower($sources))->not->toContain('trusted')
            ->and($sources)->not->toContain('ai-harness-other')
            ->and($sources)->not->toContain('Architectures');
    });
}

test('cloud provision uses a selected sury source with more than one Signed-By value as it is', function (): void {
    [$root, $environment] = cloud_php_repository_fixture();
    file_put_contents($root.'/sury.sources', "Types: deb\nURIs: https://packages.sury.org/php/\nSuites: noble\nComponents: main\nSigned-By:\n -----BEGIN PGP PUBLIC KEY BLOCK-----\n .\n -----END PGP PUBLIC KEY BLOCK-----\n");
    $environment['AI_HARNESS_APT_EXTRA_SOURCES'] = $root.'/sury.sources';
    $process = new Process(['bash', $root.'/.ai-harness-cloud', 'provision'], $root, $environment);
    $process->mustRun();

    expect($root.'/commands.curl')->not->toBeFile()
        ->and($root.'/apt-sources/ai-harness-php.sources')->not->toBeFile()
        ->and($root.'/keyrings')->not->toBeDirectory();
});

test('cloud provision probes without --retry-all-errors when curl does not support it', function (): void {
    [$root, $environment] = cloud_php_repository_fixture();
    $environment['CURL_OLD'] = '1';
    $process = new Process(['bash', $root.'/.ai-harness-cloud', 'provision'], $root, $environment);
    $process->mustRun();
    $curl = (string) file_get_contents($root.'/commands.curl');

    expect($curl)->toContain('--retry 3', 'https://packages.sury.org/php/dists/noble/Release')
        ->and($curl)->not->toContain('--retry-all-errors')
        ->and(file_get_contents($root.'/apt-sources/ai-harness-php.sources'))->toContain('URIs: https://packages.sury.org/php');
});

foreach (['selected extra source' => 'extra', 'registered image source' => 'image'] as $scenario => $location) {
    test('cloud provision reuses an enabled Launchpad source when sury does not answer: '.$scenario, function () use ($location): void {
        [$root, $environment] = cloud_php_repository_fixture();
        $environment['CURL_DOWN'] = 'packages.sury.org';
        $existing = $location === 'extra' ? $root.'/ondrej.sources' : $root.'/apt-sources/ondrej.sources';
        file_put_contents($existing, "Types: deb\nURIs: https://ppa.launchpadcontent.net/ondrej/php/ubuntu/\nSuites: noble\nComponents: main\nSigned-By: {$root}/keyrings-image/ondrej.gpg\n");

        if ($location === 'extra') {
            $environment['AI_HARNESS_APT_EXTRA_SOURCES'] = $existing;
        }

        $process = new Process(['bash', $root.'/.ai-harness-cloud', 'provision'], $root, $environment);
        $process->mustRun();
        $sources = (string) file_get_contents($root.'/commands.sources');

        $curl = (string) file_get_contents($root.'/commands.curl');

        // A registered source is used only when Launchpad answers.
        expect(str_contains($curl, 'launchpadcontent.net/ondrej/php/ubuntu/dists/noble/Release'))->toBe($location === 'image')
            ->and($curl)->not->toContain('keyserver')
            ->and($root.'/apt-sources/ai-harness-php.sources')->not->toBeFile()
            // A selected source is used as it is; a registered one gets a copy of its keyring.
            ->and($sources)->toMatch($location === 'extra'
                ? '#'.preg_quote('Signed-By: '.$root.'/keyrings-image/ondrej.gpg', '#').'\n#'
                : '#Signed-By: \S+/php-repository-reused[.]gpg\n#')
            ->and(file_get_contents($root.'/commands.keyrings'))->toContain('image key from ondrej/php')
            ->and($sources)->not->toContain('ai-harness-php.gpg');
    });
}

foreach (['patch version' => '8.5.1', 'unsupported version' => '7.4'] as $scenario => $version) {
    test('cloud provision validates an explicit PHP version before it registers a repository: '.$scenario, function () use ($version): void {
        [$root, $environment] = cloud_php_repository_fixture();
        $environment['AI_HARNESS_PHP_VERSION'] = $version;
        $process = new Process(['bash', $root.'/.ai-harness-cloud', 'provision'], $root, $environment);
        $process->run();

        expect($process->getExitCode())->toBe(1)
            ->and($root.'/commands.curl')->not->toBeFile()
            ->and($root.'/apt-sources/ai-harness-php.sources')->not->toBeFile()
            ->and($root.'/keyrings')->not->toBeDirectory()
            ->and($root.'/commands')->not->toBeFile();
    });
}

test('cloud provision does not reuse an image Launchpad source when Launchpad does not answer', function (): void {
    [$root, $environment] = cloud_php_repository_fixture();
    $environment['CURL_DOWN'] = 'packages.sury.org ppa.launchpadcontent.net';
    file_put_contents($root.'/apt-sources/ondrej.sources', "Types: deb\nURIs: https://ppa.launchpadcontent.net/ondrej/php/ubuntu/\nSuites: noble\nComponents: main\nSigned-By: {$root}/keyrings-image/ondrej.gpg\n");
    $process = new Process(['bash', $root.'/.ai-harness-cloud', 'provision'], $root, $environment);
    $process->mustRun();

    expect($process->getErrorOutput())->toContain('PHP repository https://ppa.launchpadcontent.net/ondrej/php/ubuntu does not answer for noble.')
        ->toContain('Continuing without a PHP repository')
        ->and((string) file_get_contents($root.'/commands.sources'))->not->toContain('launchpadcontent');
});

test('cloud provision names ca-certificates when curl cannot verify the repository certificate', function (): void {
    [$root, $environment] = cloud_php_repository_fixture();
    $environment['CURL_TLS_FAIL'] = 'packages.sury.org';
    $process = new Process(['bash', $root.'/.ai-harness-cloud', 'provision'], $root, $environment);
    $process->mustRun();

    expect($process->getErrorOutput())->toContain('curl cannot verify the TLS certificate of https://packages.sury.org/php; install ca-certificates.');
});

test('cloud provision keeps the case of a selected repository path', function (): void {
    [$root, $environment] = cloud_php_repository_fixture();
    file_put_contents($root.'/sury.list', "deb [signed-by={$root}/keyrings-image/sury.gpg] https://packages.sury.org/PHP/ noble main\n");
    $environment['AI_HARNESS_APT_EXTRA_SOURCES'] = $root.'/sury.list';
    $process = new Process(['bash', $root.'/.ai-harness-cloud', 'provision'], $root, $environment);
    $process->mustRun();

    expect(file_get_contents($root.'/apt-sources/ai-harness-php.sources'))->toContain('URIs: https://packages.sury.org/php');
});

test('cloud provision does not reuse an image sury source when sury does not answer', function (): void {
    [$root, $environment] = cloud_php_repository_fixture();
    $environment['CURL_DOWN'] = 'packages.sury.org';
    file_put_contents($root.'/apt-sources/php.list', "deb [signed-by={$root}/keyrings-image/sury.gpg] https://packages.sury.org/php/ noble main\n");
    $process = new Process(['bash', $root.'/.ai-harness-cloud', 'provision'], $root, $environment);
    $process->mustRun();
    $sources = (string) file_get_contents($root.'/commands.sources');

    expect(file_get_contents($root.'/apt-sources/ai-harness-php.sources'))->toContain('URIs: https://ppa.launchpadcontent.net/ondrej/php/ubuntu')
        ->and($sources)->not->toContain('packages.sury.org');
});

test('cloud provision reuses sury from the default source list when no base source is selected', function (): void {
    [$root, $environment] = cloud_php_repository_fixture();
    file_put_contents($root.'/sources.list', "deb https://base.invalid noble main\ndeb [signed-by={$root}/keyrings-image/sury.gpg] https://packages.sury.org/php/ noble main\n");
    $environment['AI_HARNESS_APT_SOURCE_LIST'] = '';
    $environment['AI_HARNESS_APT_DEFAULT_SOURCE_LIST'] = $root.'/sources.list';
    $process = new Process(['bash', $root.'/.ai-harness-cloud', 'provision'], $root, $environment);
    $process->mustRun();

    expect($root.'/commands.curl')->not->toBeFile()
        ->and($root.'/apt-sources/ai-harness-php.sources')->not->toBeFile()
        ->and(file_get_contents($root.'/commands'))->toContain('Dir::Etc::sourcelist='.$root.'/sources.list', 'Dir::Etc::sourceparts=-');
});

foreach ([
    'list entry' => ['php.list', "deb https://packages.sury.org/php/ jammy main\n"],
    'deb822 stanza' => ['php.sources', "Types: deb\nURIs: https://packages.sury.org/php/\nSuites: jammy\nComponents: main\n"],
] as $scenario => [$file, $contents]) {
    test('cloud provision does not reuse a sury source for another suite: '.$scenario, function () use ($file, $contents): void {
        [$root, $environment] = cloud_php_repository_fixture();
        file_put_contents($root.'/apt-sources/'.$file, $contents);
        $process = new Process(['bash', $root.'/.ai-harness-cloud', 'provision'], $root, $environment);
        $process->mustRun();

        expect(file_get_contents($root.'/apt-sources/ai-harness-php.sources'))->toContain('URIs: https://packages.sury.org/php', 'Suites: noble')
            ->and(file_get_contents($root.'/commands.sources'))->not->toContain('jammy');
    });
}

foreach ([
    'list file' => ['php.list', "deb https://vendor.invalid/ noble main\ndeb [signed-by={root}/keyrings-image/sury.gpg] https://packages.sury.org/php/ noble main\n"],
    'deb822 file' => ['php.sources', "Types: deb\nURIs: https://vendor.invalid/\nSuites: noble\nComponents: main\n\nTypes: deb\nURIs: https://packages.sury.org/php/\nSuites: noble\nComponents: main\nSigned-By: {root}/keyrings-image/sury.gpg\n"],
] as $scenario => [$file, $contents]) {
    test('cloud provision reuses only the matching entries of a source file: '.$scenario, function () use ($file, $contents): void {
        [$root, $environment] = cloud_php_repository_fixture();
        file_put_contents($root.'/apt-sources/'.$file, str_replace('{root}', $root, $contents));
        $process = new Process(['bash', $root.'/.ai-harness-cloud', 'provision'], $root, $environment);
        $process->mustRun();
        $sources = (string) file_get_contents($root.'/commands.sources');

        expect($sources)->toContain('URIs: https://packages.sury.org/php')
            ->toMatch('#Signed-By: \S+/php-repository-reused[.]gpg\n#')
            ->and($sources)->not->toContain('vendor.invalid')
            ->and($root.'/apt-sources/ai-harness-php.sources')->not->toBeFile();
    });
}

test('cloud provision stops before it writes a key or source without a base source list', function (): void {
    [$root, $environment] = cloud_php_repository_fixture();
    $environment['AI_HARNESS_PHP_REPOSITORY'] = 'sury';
    $environment['AI_HARNESS_APT_SOURCE_LIST'] = '';
    $environment['AI_HARNESS_APT_DEFAULT_SOURCE_LIST'] = $root.'/missing.list';
    $process = new Process(['bash', $root.'/.ai-harness-cloud', 'provision'], $root, $environment);
    $process->run();

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('requires a base source list')
        ->and($root.'/commands.curl')->not->toBeFile()
        ->and($root.'/keyrings')->not->toBeDirectory()
        ->and($root.'/apt-sources/ai-harness-php.sources')->not->toBeFile();
});

test('cloud provision leaves an unavailable sury source out when it continues with the default source list', function (): void {
    [$root, $environment] = cloud_php_repository_fixture();
    file_put_contents($root.'/sources.list', "deb https://base.invalid noble main\n");
    file_put_contents($root.'/apt-sources/ai-harness-php.sources', "Types: deb\nURIs: https://packages.sury.org/php\nSuites: noble\nComponents: main\n");
    $environment['AI_HARNESS_APT_SOURCE_LIST'] = '';
    $environment['AI_HARNESS_APT_DEFAULT_SOURCE_LIST'] = $root.'/sources.list';
    $environment['CURL_DOWN'] = 'packages.sury.org launchpadcontent.net';
    $process = new Process(['bash', $root.'/.ai-harness-cloud', 'provision'], $root, $environment);
    $process->mustRun();

    expect($process->getErrorOutput())->toContain('Continuing without a PHP repository')
        ->and(file_get_contents($root.'/commands'))->toContain('Dir::Etc::sourcelist='.$root.'/sources.list', 'Dir::Etc::sourceparts=-');
});

test('cloud provision falls back to Launchpad when the sury keyring holds another key too', function (): void {
    [$root, $environment] = cloud_php_repository_fixture();
    $environment['GPG_EXTRA_KEY'] = '1';
    $environment['AI_HARNESS_PHP_REPOSITORY'] = 'sury';
    $process = new Process(['bash', $root.'/.ai-harness-cloud', 'provision'], $root, $environment);
    $process->run();

    // The Launchpad key gets the extra key too, so provisioning stops before it writes a source.
    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('The sury signing key is not one valid key with fingerprint', 'The Launchpad signing key is not one valid key with fingerprint')
        ->and($root.'/apt-sources/ai-harness-php.sources')->not->toBeFile();
});

foreach ([
    'list entry without a keyring' => ['php.list', "deb https://packages.sury.org/php/ noble main\n"],
    'deb822 stanza with two keyrings' => ['php.sources', "Types: deb\nURIs: https://packages.sury.org/php/\nSuites: noble\nComponents: main\nSigned-By:\n {root}/keyrings-image/ondrej.gpg\n {root}/keyrings-image/sury.gpg\n"],
    'deb822 stanza with an embedded key' => ['php.sources', "Types: deb\nURIs: https://packages.sury.org/php/\nSuites: noble\nComponents: main\nSigned-By:\n -----BEGIN PGP PUBLIC KEY BLOCK-----\n .\n -----END PGP PUBLIC KEY BLOCK-----\n"],
    'list entry with an unrelated keyring' => ['php.list', "deb [signed-by={root}/keyrings-image/ondrej.gpg] https://packages.sury.org/php/ noble main\n"],
    'list entry with a missing keyring' => ['php.list', "deb [signed-by={root}/keyrings-image/missing.gpg] https://packages.sury.org/php/ noble main\n"],
    'source-only deb822 stanza' => ['php.sources', "Types: deb-src\nURIs: https://packages.sury.org/php/\nSuites: noble\nComponents: main\n"],
    'source-only list entry' => ['php.list', "deb-src [signed-by={root}/keyrings-image/sury.gpg] https://packages.sury.org/php/ noble main\n"],
] as $scenario => [$file, $contents]) {
    test('cloud provision uses the sury PHP repository for this run only when an existing entry cannot be used: '.$scenario, function () use ($file, $contents): void {
        [$root, $environment] = cloud_php_repository_fixture();
        file_put_contents($root.'/apt-sources/'.$file, str_replace('{root}', $root, $contents));
        $process = new Process(['bash', $root.'/.ai-harness-cloud', 'provision'], $root, $environment);
        $process->mustRun();

        expect($process->getErrorOutput())->toContain('for this run only; '.$root.'/apt-sources/'.$file.' already has an entry for it')
            ->and($root.'/apt-sources/ai-harness-php.sources')->not->toBeFile()
            // The persistent keyring stays as it is; the run-only entry has its own key.
            ->and($root.'/keyrings/ai-harness-php.gpg')->not->toBeFile()
            ->and(file_get_contents($root.'/commands.sources'))->toMatch('#'.preg_quote("URIs: https://packages.sury.org/php\nSuites: noble\nComponents: main\nSigned-By: ", '#').'\S+/php-repository[.]gpg\n#')
            ->and(file_get_contents($root.'/commands.keyrings'))->toMatch('#/php-repository[.]gpg 644 755: key from https://packages[.]sury[.]org/php/apt[.]gpg\n#');
    });
}
