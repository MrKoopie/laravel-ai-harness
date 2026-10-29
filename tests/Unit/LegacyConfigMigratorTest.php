<?php

declare(strict_types=1);

use MrKoopie\LaravelAiHarness\Config\ConfigLoader;
use MrKoopie\LaravelAiHarness\Environment\Runtime;
use MrKoopie\LaravelAiHarness\Files\LegacyConfigMigrator;
use MrKoopie\LaravelAiHarness\Files\SafeWriter;

test('legacy configuration is conservatively translated when no new config exists', function (): void {
    $root = temp_directory('harness-legacy-config');
    $migrator = new LegacyConfigMigrator(new SafeWriter);

    $result = $migrator->migrate(
        $root,
        [
            'codex' => true,
            'claude' => false,
            'herd' => true,
            'docker' => true,
            'skills' => true,
            'polyscope' => true,
        ],
        ['php_version' => '8.4'],
        [],
    );
    $config = (new ConfigLoader)->load($root);

    expect($result->created)->toBeTrue()
        ->and($config->runtime)->toBe(Runtime::Herd)
        ->and($config->sailServices)->toBe(['mysql'])
        ->and($config->agents)->toBe(['codex'])
        ->and($config->phpVersion)->toBe('8.4')
        ->and(implode("\n", $result->warnings))->toContain('skills')
        ->toContain('polyscope');
});

test('legacy command flags are translated but never overwrite a new config', function (): void {
    $root = temp_directory('harness-legacy-flags');
    $migrator = new LegacyConfigMigrator(new SafeWriter);

    $first = $migrator->migrate($root, [], [], ['herd', 'docker']);
    $contents = (string) file_get_contents($root.'/.ai-harness.config');
    $second = $migrator->migrate($root, ['codex' => false], [], []);

    expect($first->created)->toBeTrue()
        ->and($second->created)->toBeFalse()
        ->and(file_get_contents($root.'/.ai-harness.config'))->toBe($contents)
        ->and($contents)->toContain('runtime=herd')
        ->and($contents)->toContain('sail_services=mysql');
});

test('legacy migration reports obsolete package artifacts without deleting them', function (): void {
    $root = temp_directory('harness-legacy-artifacts');
    mkdir($root.'/.codex', 0755, true);
    mkdir($root.'/docker/mysql/init', 0755, true);
    file_put_contents($root.'/.codex/hooks.json', "{}\n");
    file_put_contents($root.'/docker/mysql/init/10-create-testing-database.sh', "#!/usr/bin/env bash\n");
    file_put_contents($root.'/polyscope.json', "{}\n");

    $result = (new LegacyConfigMigrator(new SafeWriter))->migrate($root, [], [], []);
    $warnings = implode("\n", $result->warnings);

    expect($root.'/.codex/hooks.json')->toBeFile()
        ->and($root.'/docker/mysql/init/10-create-testing-database.sh')->toBeFile()
        ->and($root.'/polyscope.json')->toBeFile()
        ->and($warnings)->toContain('.codex/hooks.json')
        ->toContain('docker/mysql/init/10-create-testing-database.sh')
        ->toContain('polyscope.json');
});

test('legacy migration preserves global installation settings', function (): void {
    $root = temp_directory('harness-legacy-global');
    file_put_contents($root.'/.ai-harness.config.global', "runtime=valet\n");
    $result = (new LegacyConfigMigrator(new SafeWriter))->migrate($root, ['herd' => true], [], []);

    expect($result->created)->toBeFalse()
        ->and($root.'/.ai-harness.config')->not->toBeFile()
        ->and((new ConfigLoader)->load($root)->runtime)->toBe(Runtime::Valet);
});
