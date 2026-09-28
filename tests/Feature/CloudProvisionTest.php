<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/** @return array{string, array<string, string>} */
function cloud_provision_fixture(): array
{
    $root = temp_directory('cloud-provision');
    mkdir($root.'/bin');
    file_put_contents($root.'/ubuntu.sources', "Types: deb\nURIs: https://base.invalid\nSuites: noble\nComponents: main\n");
    file_put_contents($root.'/php.list', "deb [signed-by=/usr/share/keyrings/php.gpg] https://php.invalid noble main\n");
    file_put_contents($root.'/extra.sources', "Types: deb\nURIs: https://extra.invalid\nSuites: noble\nComponents: main\n");

    foreach (['id' => '0', 'uname' => 'Linux', 'apt-cache' => '  Depends: php8.5-cli'] as $command => $output) {
        write_executable($root.'/bin/'.$command, "#!/bin/sh\necho '".$output."'\n");
    }

    foreach (['update-alternatives', 'php', 'composer', 'node', 'npm'] as $command) {
        write_executable($root.'/bin/'.$command, "#!/bin/sh\nprintf '%s\\n' \"\$*\" >> \"\$CLOUD_LOG\"\n");
    }

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
exit "${APT_EXIT:-0}"
BASH);

    return [$root, [
        'AI_HARNESS_ENV' => 'claude-cloud',
        'AI_HARNESS_APT_SOURCE_LIST' => $root.'/ubuntu.sources',
        'AI_HARNESS_APT_EXTRA_SOURCES' => '',
        'AI_HARNESS_PHP_EXTENSIONS' => '',
        'AI_HARNESS_PHP_VERSION' => '8.5',
        'PATH' => $root.'/bin'.PATH_SEPARATOR.getenv('PATH'),
        'CLOUD_LOG' => $root.'/commands',
        'APT_EXIT' => '0',
    ]];
}

foreach (['success' => '0', 'apt failure' => '42'] as $scenario => $exit) {
    test('cloud provision includes selected extra sources alongside the base and cleans temporary files: '.$scenario, function () use ($exit): void {
        [$root, $environment] = cloud_provision_fixture();
        $environment['AI_HARNESS_APT_EXTRA_SOURCES'] = $root.'/php.list:'.$root.'/extra.sources';
        $environment['APT_EXIT'] = $exit;
        $process = new Process(['bash', package_root().'/resources/project/cloud.sh', 'provision'], $root, $environment);
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
    $process = new Process(['bash', package_root().'/resources/project/cloud.sh', 'provision'], $root, $environment);
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
        $process = new Process(['bash', package_root().'/resources/project/cloud.sh', 'provision'], $root, $environment);
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
    $process = new Process(['bash', package_root().'/resources/project/cloud.sh', 'provision'], $root, $environment);
    $process->mustRun();

    $directory = file_get_contents($root.'/commands.directory');
    expect(file_get_contents($root.'/commands.discovery'))->toContain('Dir::Etc::sourcelist='.$root.'/ubuntu.sources', 'Dir::Etc::sourceparts='.$directory, 'depends php-cli')
        ->and(file_get_contents($root.'/commands'))->toContain('sudo', 'php8.5-imagick', 'php8.5-soap', '--set php /usr/bin/php8.5');
});
