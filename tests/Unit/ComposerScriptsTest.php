<?php

declare(strict_types=1);

use MrKoopie\LaravelAiHarness\Files\ComposerScripts;
use MrKoopie\LaravelAiHarness\Files\FileException;
use MrKoopie\LaravelAiHarness\Files\SafeWriter;
use Symfony\Component\Process\Process;

test('composer refresh hooks preserve unrelated scripts and replace only recognized harness hooks', function (): void {
    $root = temp_directory('harness-composer-scripts');
    $legacy = <<<'SCRIPT'
@php -r "if (file_exists('vendor/mrkoopie/laravel-ai-harness')) { passthru(escapeshellarg(PHP_BINARY).' artisan ai-harness:update --ansi --with=herd', $code); exit($code); }"
SCRIPT;
    file_put_contents($root.'/composer.json', json_encode([
        'name' => 'example/application',
        'extra' => (object) [],
        'scripts' => [
            'post-install-cmd' => 'echo install-custom',
            'post-update-cmd' => [
                'echo before',
                $legacy,
                'echo ai-harness:update belongs-to-the-project',
                'echo '.ComposerScripts::MARKER.' belongs-to-the-project',
                $legacy.' && echo keep-custom-chain',
                'echo after',
            ],
            'test' => 'pest',
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");

    $scripts = new ComposerScripts(new SafeWriter);

    expect($scripts->sync($root))->toBeTrue();
    $first = (string) file_get_contents($root.'/composer.json');

    expect($scripts->sync($root))->toBeFalse()
        ->and(file_get_contents($root.'/composer.json'))->toBe($first)
        ->and($scripts->installed($root))->toBeTrue();

    $composer = json_decode($first, true, flags: JSON_THROW_ON_ERROR);
    $install = $composer['scripts']['post-install-cmd'];
    $update = $composer['scripts']['post-update-cmd'];

    expect($composer['extra'])->toBe([])
        ->and($composer['scripts']['test'])->toBe('pest')
        ->and($install[0])->toBe('echo install-custom')
        ->and($update[0])->toBe('echo before')
        ->and($update[1])->toBe('echo ai-harness:update belongs-to-the-project')
        ->and($update[2])->toBe('echo '.ComposerScripts::MARKER.' belongs-to-the-project')
        ->and($update[3])->toBe($legacy.' && echo keep-custom-chain')
        ->and($update[4])->toBe('echo after')
        ->and(count(array_filter($install, static fn (string $script): bool => $script === ComposerScripts::hook())))->toBe(1)
        ->and(count(array_filter($update, static fn (string $script): bool => $script === ComposerScripts::hook())))->toBe(1)
        ->and(in_array($legacy, $update, true))->toBeFalse();
});

test('composer health check does not mistake a marker-containing custom script for the managed hook', function (): void {
    $root = temp_directory('harness-composer-health');
    file_put_contents($root.'/composer.json', json_encode([
        'scripts' => [
            'post-install-cmd' => ['echo '.ComposerScripts::MARKER],
            'post-update-cmd' => ['echo '.ComposerScripts::MARKER],
        ],
    ], JSON_THROW_ON_ERROR));

    expect((new ComposerScripts(new SafeWriter))->installed($root))->toBeFalse();
});

test('composer refresh hook is guarded for production and missing development dependencies', function (): void {
    $hook = ComposerScripts::hook();

    expect($hook)->toContain("getenv('COMPOSER_DEV_MODE') === '0'")
        ->toContain("is_file('vendor/bin/ai-harness')")
        ->toContain('escapeshellarg(PHP_BINARY)')
        ->toContain('update --no-interaction')
        ->toContain('exit(\\$status)');
});

test('composer refresh hook skips production and propagates refresh failures', function (): void {
    $root = temp_directory('harness-composer-hook-execution');
    $command = str_replace('@php', escapeshellarg(PHP_BINARY), ComposerScripts::hook());

    $production = new Process(['/bin/sh', '-c', $command], $root, ['COMPOSER_DEV_MODE' => '0']);
    $production->mustRun();

    mkdir($root.'/vendor/bin', 0755, true);
    file_put_contents($root.'/vendor/bin/ai-harness', "<?php exit(23);\n");
    $development = new Process(['/bin/sh', '-c', $command], $root, ['COMPOSER_DEV_MODE' => '1']);
    $development->run();

    expect($production->getExitCode())->toBe(0)
        ->and($development->getExitCode())->toBe(23);
});

test('composer refresh is a no-op when the project has no composer file', function (): void {
    $root = temp_directory('harness-no-composer');

    expect((new ComposerScripts(new SafeWriter))->sync($root))->toBeFalse();
});

test('composer refresh reports malformed composer json clearly', function (): void {
    $root = temp_directory('harness-bad-composer');
    file_put_contents($root.'/composer.json', "{broken\n");

    expect(fn (): bool => (new ComposerScripts(new SafeWriter))->sync($root))
        ->toThrow(FileException::class, 'Invalid composer.json');
});

test('composer refresh rejects invalid script event shapes instead of deleting them', function (): void {
    $root = temp_directory('harness-bad-composer-scripts');
    file_put_contents($root.'/composer.json', json_encode([
        'scripts' => ['post-update-cmd' => ['valid', 42]],
    ], JSON_THROW_ON_ERROR));

    expect(fn (): bool => (new ComposerScripts(new SafeWriter))->sync($root))
        ->toThrow(FileException::class, 'must be a string or an array of strings');
});
