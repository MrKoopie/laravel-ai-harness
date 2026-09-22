<?php

declare(strict_types=1);
use MrKoopie\LaravelAiHarness\Config\Config;
use MrKoopie\LaravelAiHarness\Config\ConfigLoader;
use MrKoopie\LaravelAiHarness\Environment\CommandFactory;
use MrKoopie\LaravelAiHarness\Environment\Runtime;
use MrKoopie\LaravelAiHarness\Environment\Services;
use MrKoopie\LaravelAiHarness\Process\ExecutableLocator;

test('Herd runtime tolerates noisy PHP resolution while preserving arguments and exit status', function (): void {
    $root = temp_directory('harness-noisy-herd');
    $bin = $root.'/bin';
    file_put_contents($root.'/.ai-harness.config', "runtime=herd\nservices=none\nagents=\n");
    write_executable($bin.'/php84', "#!/usr/bin/env bash\nprintf '%s\\n' \"\$@\"\nexit 23\n");
    write_executable($bin.'/herd', '#!/usr/bin/env bash'."\n".'if [[ "$1" == which-php ]]; then'."\n".'printf "Warning: simulated startup warning\\n%s\\n" '.escapeshellarg($bin.'/php84')."\nexit 0\nfi\n".'"$("$0" which-php)" "${@:2}"'."\n");
    write_executable($bin.'/php', '#!/usr/bin/env bash'."\n".'if [[ "$1" != -d ]]; then exec '.escapeshellarg(PHP_BINARY).' "$@"; fi'."\n".'printf "Warning: simulated startup warning\\n%s\\n" '.escapeshellarg($bin.'/php84')."\n");
    file_put_contents($bin.'/herd.phar', 'fixture');
    write_executable($bin.'/composer', "#!/usr/bin/env php\n<?php\n");
    $env = ['PATH' => $bin.PATH_SEPARATOR.getenv('PATH')];

    $factory = new CommandFactory(new ExecutableLocator(overrides: ['herd' => $bin.'/herd']));
    $config = (new ConfigLoader)->load($root);
    expect($factory->runtime($config, 'php', ['--site=other', '-v'], $root))->toBe([$bin.'/herd', 'php', '--site=other', '-v']);

    foreach ([['php', 'value with spaces', '$(not executed)'], ['artisan', 'about'], ['test', '--parallel'], ['composer', 'validate']] as $args) {
        $process = harness_process($args, $root, $env);
        $process->run();
        expect($process->getExitCode())->toBe(23)
            ->and($process->getOutput())->toContain(end($args))
            ->and($process->getOutput())->not->toContain('Warning: simulated');
    }
});

foreach (['outside', 'missing', 'not-php', 'failure', 'not-executable', 'missing-layout'] as $candidate) {
    test('Herd resolver refuses '.$candidate.' candidates and keeps the wrapper fallback', function () use ($candidate): void {
        $root = temp_directory('harness-invalid-herd');
        $bin = $root.'/bin';
        write_executable($bin.'/herd', "#!/usr/bin/env bash\nexit 0\n");
        file_put_contents($bin.'/herd.phar', 'fixture');
        write_executable($bin.'/php84', "#!/usr/bin/env bash\nexit 0\n");
        $value = match ($candidate) {
            'outside' => PHP_BINARY,
            'missing' => $bin.'/php99',
            'not-php' => $bin.'/herd',
            default => $bin.'/php84',
        };
        if ($candidate === 'not-executable') {
            chmod($bin.'/php84', 0644);
        }

        if ($candidate === 'missing-layout') {
            unlink($bin.'/herd.phar');
        }

        $exit = $candidate === 'failure' ? 1 : 0;
        write_executable($bin.'/php', "#!/usr/bin/env bash\nprintf '%s\\n' ".escapeshellarg($value)."\nexit $exit\n");
        $factory = new CommandFactory(new ExecutableLocator(overrides: ['herd' => $bin.'/herd']));

        expect($factory->runtime(new Config(Runtime::Herd, Services::None, [], [], true, null, true, []), 'php', ['-v'], $root))
            ->toBe([$bin.'/herd', 'php', '-v']);
    });
}
