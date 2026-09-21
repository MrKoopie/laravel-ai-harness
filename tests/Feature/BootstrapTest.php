<?php

declare(strict_types=1);

use MrKoopie\LaravelAiHarness\Config\ConfigLoader;
use MrKoopie\LaravelAiHarness\Files\ClaudeSettings;
use MrKoopie\LaravelAiHarness\Files\ProjectInstaller;
use MrKoopie\LaravelAiHarness\Files\SafeWriter;
use Symfony\Component\Process\Process;

test('thin bootstrap installs locked dependencies once and executes the package binary', function (): void {
    $root = temp_directory('harness-bootstrap');
    $fakeBin = $root.'/fake-bin';
    $composerLog = temp_file('composer-log');
    $binaryLog = temp_file('binary-log');
    mkdir($fakeBin, 0755, true);

    $writer = new SafeWriter;
    $installer = new ProjectInstaller($writer, new ClaudeSettings($writer));
    $installer->ensureConfig($root);
    $installer->install($root, (new ConfigLoader)->load($root));

    write_executable($fakeBin.'/composer', <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "$*" >> "${COMPOSER_LOG}"
mkdir -p vendor/bin
cp "${FAKE_HARNESS}" vendor/bin/ai-harness
chmod +x vendor/bin/ai-harness
BASH);
    $fakeHarness = $root.'/fake-harness';
    write_executable($fakeHarness, <<<'BASH'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "${BINARY_LOG}"
BASH);

    $environment = [
        'PATH' => $fakeBin.PATH_SEPARATOR.getenv('PATH'),
        'COMPOSER_LOG' => $composerLog,
        'FAKE_HARNESS' => $fakeHarness,
        'BINARY_LOG' => $binaryLog,
    ];

    (new Process([$root.'/.ai-harness', 'doctor', '--path', $root], $root, $environment))->mustRun();
    (new Process([$root.'/.ai-harness', 'up'], $root, $environment))->mustRun();

    expect(trim((string) file_get_contents($composerLog)))
        ->toBe('install --no-interaction --prefer-dist')
        ->and(file($binaryLog, FILE_IGNORE_NEW_LINES))->toBe([
            'doctor --path '.$root,
            'up',
        ]);
});

test('thin bootstrap never mutates composer requirements when the package binary stays missing', function (): void {
    $root = temp_directory('harness-bootstrap-missing');
    $fakeBin = $root.'/fake-bin';
    mkdir($fakeBin, 0755, true);
    copy(package_root().'/resources/project/bootstrap.sh', $root.'/.ai-harness');
    chmod($root.'/.ai-harness', 0755);
    write_executable($fakeBin.'/composer', "#!/usr/bin/env bash\nexit 0\n");

    $process = new Process([$root.'/.ai-harness', 'doctor'], $root, [
        'PATH' => $fakeBin.PATH_SEPARATOR.getenv('PATH'),
    ]);
    $process->run();

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('missing from composer.json or composer.lock');
});
