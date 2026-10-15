<?php

declare(strict_types=1);

use MrKoopie\LaravelAiHarness\Config\ConfigLoader;
use MrKoopie\LaravelAiHarness\Environment\Runtime;
use Symfony\Component\Process\Process;

test('global init configures agents without a project dependency or Composer hooks', function (): void {
    $root = temp_directory('harness-global');
    $composer = "{\"name\":\"example/app\"}\n";
    file_put_contents($root.'/composer.json', $composer);
    file_put_contents($root.'/artisan', '<?php');
    file_put_contents($root.'/.gitignore', "custom-ignore\n");
    (new Process(['git', 'init', $root]))->mustRun();

    harness_process(['init', '--global', '--path', $root], $root)->mustRun();

    expect($root.'/.ai-harness.config.global')->toBeFile()
        ->and($root.'/.ai-harness.config')->not->toBeFile()
        ->and($root.'/vendor')->not->toBeDirectory()
        ->and(file_get_contents($root.'/composer.json'))->toBe($composer)
        ->and(file_get_contents($root.'/.gitignore'))->toBe("custom-ignore\n")
        ->and(file_get_contents($root.'/.codex/environments/ai-harness-global.toml'))->toContain('ai-harness init --global && ai-harness setup')
        ->and(file_get_contents($root.'/.claude/settings.local.json'))->toContain('hook claude')
        ->and($root.'/AGENTS.override.md')->toBeFile();

    (new Process(['git', 'check-ignore', '.ai-harness.config.global'], $root))->mustRun();
    $status = new Process(['git', 'ls-files', '--others', '--exclude-standard'], $root);
    $status->mustRun();
    expect($status->getOutput())->toBe(".gitignore\nartisan\ncomposer.json\n");
    expect($root.'/CLAUDE.local.md')->toBeFile()
        ->and($root.'/AGENTS.md')->not->toBeFile()
        ->and($root.'/.claude/settings.json')->not->toBeFile();

    file_put_contents($root.'/.ai-harness.config.global', "agents=codex\ncloud=false\nlocal_env.DB_PORT=3307\n");
    harness_process(['update', '--path', $root], $root)->mustRun();
    harness_process(['init', '--path', $root], $root)->mustRun();

    expect(file_get_contents($root.'/composer.json'))->toBe($composer)
        ->and(file_get_contents($root.'/.ai-harness.config.global'))->toContain('local_env.DB_PORT=3307')
        ->and($root.'/.ai-harness-cloud')->not->toBeFile()
        ->and(file_get_contents($root.'/.gitignore'))->toBe("custom-ignore\n")
        ->and(substr_count((string) file_get_contents($root.'/.git/info/exclude'), '# ai-harness:start'))->toBe(1);

    $doctor = harness_process(['doctor', '--path', $root], $root, ['PATH' => package_root().'/bin'.PATH_SEPARATOR.getenv('PATH')]);
    $doctor->mustRun();
    expect($doctor->getOutput())->not->toContain('Composer refresh hooks are missing');
});

test('linked worktrees inherit global settings and retain global mode on update', function (): void {
    $primary = temp_directory('harness-global-primary');
    $worktree = temp_directory('harness-global-worktree');
    (new Process(['git', 'init', '--initial-branch=main', $primary]))->mustRun();
    (new Process(['git', '-c', 'user.name=Harness Test', '-c', 'user.email=harness@example.invalid', 'commit', '--allow-empty', '--no-gpg-sign', '-m', 'Fixture'], $primary))->mustRun();
    (new Process(['git', 'worktree', 'add', '--detach', $worktree], $primary))->mustRun();
    file_put_contents($primary.'/.ai-harness.config.global', "runtime=valet\nphp_version=8.3\nlocal_env.DB_PORT=3307\n");
    file_put_contents($worktree.'/composer.json', '{}');

    try {
        $config = (new ConfigLoader)->load($worktree);
        expect($config->runtime)->toBe(Runtime::Valet)
            ->and($config->localEnvironment['DB_PORT'])->toBe('3307');
        harness_process(['update'], $worktree)->mustRun();
        expect(file_get_contents($worktree.'/composer.json'))->toBe('{}')
            ->and($worktree.'/.ai-harness.config')->not->toBeFile()
            ->and($worktree.'/.ai-harness.config.global')->not->toBeFile()
            ->and(file_get_contents($worktree.'/.ai-harness'))->toContain('exec ai-harness');

        file_put_contents($worktree.'/.ai-harness.config.global', "local_env.DB_PORT=3308\n");
        $config = (new ConfigLoader)->load($worktree);
        expect($config->runtime)->toBe(Runtime::Valet)
            ->and($config->localEnvironment['DB_PORT'])->toBe('3308');
    } finally {
        (new Process(['git', 'worktree', 'remove', '--force', $worktree], $primary))->mustRun();
    }
});

test('global settings receive the same validation as project settings', function (): void {
    $root = temp_directory('harness-global-invalid');
    (new Process(['git', 'init', $root]))->mustRun();
    file_put_contents($root.'/.ai-harness.config.global', "runtime=unknown\n");
    $update = harness_process(['update'], $root);
    $update->run();
    expect($update->getExitCode())->toBe(1)
        ->and($update->getErrorOutput())->toContain('runtime must be one of')
        ->and($root.'/.ai-harness')->not->toBeFile();
});

test('global configuration supports all settings and local overrides win', function (): void {
    $root = temp_directory('harness-global-config');
    (new Process(['git', 'init', $root]))->mustRun();
    file_put_contents($root.'/.ai-harness.config', "runtime=herd\nphp_version=8.4\n");
    harness_process(['init', '--global'], $root)->mustRun();
    expect((new ConfigLoader)->load($root)->runtime)->toBe(Runtime::Herd);

    file_put_contents($root.'/.ai-harness.config.global', "runtime=valet\nphp_version=8.3\nagents=codex\nlocal_env.DB_PORT=3307\n");
    file_put_contents($root.'/.ai-harness.config.local', "runtime=native\nlocal_env.DB_PORT=3308\n");
    $config = (new ConfigLoader)->load($root);
    expect($config->runtime)->toBe(Runtime::Native)
        ->and($config->phpVersion)->toBe('8.3')
        ->and($config->agents)->toBe(['codex'])
        ->and($config->localEnvironment['DB_PORT'])->toBe('3308');
});

test('global bootstrap dispatches to PATH without installing project dependencies', function (): void {
    $root = temp_directory('harness-global-bootstrap');
    (new Process(['git', 'init', $root]))->mustRun();
    harness_process(['init', '--global'], $root)->mustRun();
    write_executable($root.'/tools/ai-harness', "#!/bin/sh\nprintf '%s\\n' \"\$PWD\" \"\$@\" > invoked\n");
    write_executable($root.'/tools/composer', "#!/bin/sh\ntouch unexpected-composer\nexit 1\n");
    $process = new Process([$root.'/.ai-harness', 'test', '--filter=Example Test'], sys_get_temp_dir(), [
        'PATH' => $root.'/tools'.PATH_SEPARATOR.getenv('PATH'),
    ]);
    $process->mustRun();
    expect(file_get_contents($root.'/invoked'))->toBe($root."\ntest\n--filter=Example Test\n")
        ->and($root.'/unexpected-composer')->not->toBeFile()
        ->and($root.'/vendor')->not->toBeDirectory();
});

test('global bootstrap explains a missing global command without running Composer', function (): void {
    $root = temp_directory('harness-global-missing');
    (new Process(['git', 'init', $root]))->mustRun();
    harness_process(['init', '--global'], $root)->mustRun();
    mkdir($root.'/tools');
    symlink('/bin/bash', $root.'/tools/bash');
    symlink('/usr/bin/dirname', $root.'/tools/dirname');
    $process = new Process(['/bin/bash', $root.'/.ai-harness', 'setup'], $root, ['PATH' => $root.'/tools']);
    $process->run();
    expect($process->getExitCode())->toBe(127)
        ->and($process->getErrorOutput())->toContain('composer global require mrkoopie/laravel-ai-harness')
        ->and($root.'/vendor')->not->toBeDirectory();

    $doctor = new Process([PHP_BINARY, package_root().'/bin/ai-harness', 'doctor'], $root, ['PATH' => $root.'/tools']);
    $doctor->run();
    expect($doctor->getExitCode())->toBe(1)
        ->and($doctor->getOutput())->toContain('Global AI Harness is missing from PATH');
});

test('global mode preserves shared files and personal settings across updates and disabling agents', function (): void {
    $root = temp_directory('harness-private-shared');
    (new Process(['git', 'init', $root]))->mustRun();
    mkdir($root.'/.claude');
    mkdir($root.'/.codex/environments', 0755, true);
    $shared = [
        '.gitignore' => "vendor\ncustom-ignore\n",
        'AGENTS.md' => "Shared team guidance.\n<!-- ai-harness:start -->\nOld instructions\n<!-- ai-harness:end -->\n",
        'CLAUDE.md' => "Team Claude instructions.\n",
        '.claude/settings.json' => '{"hooks":{"SessionStart":[{"hooks":[{"type":"command","command":"echo team"}]}]}}',
        '.codex/environments/environment.toml' => 'name = "Team environment"',
        '.ai-harness' => "#!/bin/sh\necho shared launcher\n",
        '.ai-harness-cloud' => "#!/bin/sh\necho shared cloud launcher\n",
    ];
    foreach ($shared as $path => $contents) {
        file_put_contents($root.'/'.$path, $contents);
    }
    (new Process(['git', 'add', '.'], $root))->mustRun();
    file_put_contents($root.'/.claude/settings.local.json', '{"permissions":{"allow":["Read"]},"hooks":{"SessionStart":[{"hooks":[{"type":"command","command":"echo personal"}]}]}}');
    file_put_contents($root.'/CLAUDE.local.md', "My local instructions.\n");
    file_put_contents($root.'/AGENTS.override.md', "My Codex instructions.\n");
    file_put_contents($root.'/.git/info/exclude', "# Keep existing excludes\nprivate-file\n");
    harness_process(['init', '--global'], $root)->mustRun();
    harness_process(['update'], $root)->mustRun();

    foreach ($shared as $path => $contents) {
        expect(file_get_contents($root.'/'.$path))->toBe($contents);
    }
    $hooks = (string) file_get_contents($root.'/.claude/settings.local.json');
    expect($hooks)->toContain('echo personal', 'Read', 'ai-harness hook claude')
        ->and(substr_count($hooks, 'ai-harness hook claude session-start'))->toBe(1)
        ->and(file_get_contents($root.'/CLAUDE.local.md'))->toStartWith('My local instructions.')
        ->and(file_get_contents($root.'/CLAUDE.local.md'))->toContain('@AGENTS.md', '`ai-harness test')
        ->and(file_get_contents($root.'/AGENTS.override.md'))->toContain('Shared team guidance.', 'My Codex instructions.')
        ->and(substr_count((string) file_get_contents($root.'/AGENTS.override.md'), '<!-- ai-harness:start -->'))->toBe(1)
        ->and(file_get_contents($root.'/.git/info/exclude'))->toStartWith("# Keep existing excludes\nprivate-file");

    file_put_contents($root.'/.ai-harness.config.global', "agents=\ncloud=false\nworktrees=false\n");
    harness_process(['update'], $root)->mustRun();
    expect(file_get_contents($root.'/.claude/settings.local.json'))->toContain('echo personal', 'Read')
        ->and(file_get_contents($root.'/.claude/settings.local.json'))->not->toContain('ai-harness hook claude')
        ->and(trim((string) file_get_contents($root.'/CLAUDE.local.md')))->toBe('My local instructions.')
        ->and(trim((string) file_get_contents($root.'/AGENTS.override.md')))->toBe('My Codex instructions.')
        ->and($root.'/.codex/environments/ai-harness-global.toml')->not->toBeFile();
    foreach ($shared as $path => $contents) {
        expect(file_get_contents($root.'/'.$path))->toBe($contents);
    }
});

test('global mode excludes nested applications without changing unrelated projects', function (): void {
    $repository = temp_directory('harness-private-nested');
    (new Process(['git', 'init', $repository]))->mustRun();
    foreach (['app [one]', 'app-two'] as $name) {
        $root = $repository.'/'.$name;
        mkdir($root);
        harness_process(['init', '--global'], $root)->mustRun();
        $ignored = new Process(['git', 'check-ignore', '.ai-harness', 'CLAUDE.local.md', '.claude/settings.local.json', '.codex/environments/ai-harness-global.toml'], $root);
        $ignored->mustRun();
        expect(count(explode("\n", trim($ignored->getOutput()))))->toBe(4)
            ->and($root.'/.gitignore')->not->toBeFile();
    }
    $status = new Process(['git', 'status', '--porcelain', '--untracked-files=all'], $repository);
    $status->mustRun();
    expect($status->getOutput())->toBe('');
});

test('global mode resolves a separate Git directory and rejects symlinked excludes', function (): void {
    $root = temp_directory('harness-private-separate');
    $metadata = temp_directory('harness-private-gitdir');
    (new Process(['git', 'init', '--separate-git-dir', $metadata, $root]))->mustRun();
    harness_process(['init', '--global'], $root)->mustRun();
    expect(file_get_contents($metadata.'/info/exclude'))->toContain('/CLAUDE.local.md')
        ->and($root.'/.gitignore')->not->toBeFile();

    $outside = temp_file('harness-private-exclude-outside');
    file_put_contents($outside, 'Do not change');
    unlink($metadata.'/info/exclude');
    symlink($outside, $metadata.'/info/exclude');
    $update = harness_process(['update'], $root);
    $update->run();
    expect($update->getExitCode())->toBe(1)
        ->and($update->getErrorOutput())->toContain('symbolic link')
        ->and(file_get_contents($outside))->toBe('Do not change');
});

test('global mode rejects tracked personal integration files before creating anything', function (): void {
    $root = temp_directory('harness-private-tracked');
    (new Process(['git', 'init', $root]))->mustRun();
    file_put_contents($root.'/CLAUDE.local.md', "Tracked instructions\n");
    (new Process(['git', 'add', 'CLAUDE.local.md'], $root))->mustRun();
    $init = harness_process(['init', '--global'], $root);
    $init->run();
    expect($init->getExitCode())->toBe(1)
        ->and($init->getErrorOutput())->toContain('cannot keep tracked file [CLAUDE.local.md] private')
        ->and(file_get_contents($root.'/CLAUDE.local.md'))->toBe("Tracked instructions\n")
        ->and($root.'/.ai-harness.config.global')->not->toBeFile()
        ->and($root.'/.ai-harness')->not->toBeFile()
        ->and($root.'/.gitignore')->not->toBeFile();
});

test('global mode requires Git metadata before writing personal integrations', function (): void {
    $root = temp_directory('harness-private-no-git');
    $init = harness_process(['init', '--global'], $root);
    $init->run();
    expect($init->getExitCode())->toBe(1)
        ->and($init->getErrorOutput())->toContain('requires Git')
        ->and($root.'/.ai-harness.config.global')->not->toBeFile()
        ->and($root.'/.gitignore')->not->toBeFile();
});

test('Claude global hooks install ignored integrations in a fresh worktree before setup', function (): void {
    $primary = temp_directory('harness-private-claude-primary');
    $worktree = $primary.'/.claude/worktrees/example';
    (new Process(['git', 'init', '--initial-branch=main', $primary]))->mustRun();
    file_put_contents($primary.'/.gitignore', ".env\nvendor/\n.claude/worktrees/\n");
    file_put_contents($primary.'/.env.example', "APP_KEY=present\n");
    file_put_contents($primary.'/artisan', '<?php');
    (new Process(['git', 'add', '.'], $primary))->mustRun();
    (new Process(['git', '-c', 'user.name=Harness Test', '-c', 'user.email=harness@example.invalid', 'commit', '--no-gpg-sign', '-m', 'Fixture'], $primary))->mustRun();
    file_put_contents($primary.'/.ai-harness.config.global', "runtime=native\nagents=claude\ncloud=false\n");
    harness_process(['init', '--global'], $primary)->mustRun();
    (new Process(['git', 'worktree', 'add', '--detach', $worktree], $primary))->mustRun();

    try {
        expect($worktree.'/.ai-harness')->not->toBeFile();
        mkdir($worktree.'/vendor');
        file_put_contents($worktree.'/vendor/autoload.php', '<?php');
        $hook = harness_process(['hook', 'claude', 'enter-worktree'], $primary, ['CLAUDE_PROJECT_DIR' => $primary]);
        $hook->setInput(json_encode(['tool_response' => ['worktree_path' => $worktree]], JSON_THROW_ON_ERROR));
        $hook->mustRun();
        expect($worktree.'/CLAUDE.local.md')->toBeFile()
            ->and($worktree.'/.env.testing')->toBeFile()
            ->and($worktree.'/.claude/settings.local.json')->toBeFile()
            ->and(file_get_contents($worktree.'/.gitignore'))->toBe(".env\nvendor/\n.claude/worktrees/\n");
        $status = new Process(['git', 'status', '--porcelain', '--untracked-files=all'], $worktree);
        $status->mustRun();
        expect($status->getOutput())->toBe('');
    } finally {
        (new Process(['git', 'worktree', 'remove', '--force', $worktree], $primary))->mustRun();
    }
});

test('global mode refuses conflicting shared ignore rules without creating exposed files', function (): void {
    $root = temp_directory('harness-private-unignore');
    (new Process(['git', 'init', $root]))->mustRun();
    file_put_contents($root.'/.gitignore', "!CLAUDE.local.md\n");
    $init = harness_process(['init', '--global'], $root);
    $init->run();
    expect($init->getExitCode())->toBe(1)
        ->and($init->getErrorOutput())->toContain('prevent keeping [CLAUDE.local.md] private')
        ->and($root.'/CLAUDE.local.md')->not->toBeFile()
        ->and($root.'/.ai-harness.config.global')->not->toBeFile()
        ->and(file_get_contents($root.'/.gitignore'))->toBe("!CLAUDE.local.md\n");
});

test('nested projects inherit their primary global configuration when initializing a linked worktree', function (): void {
    $primary = temp_directory('harness-nested-global-primary');
    $worktree = temp_directory('harness-nested-global-worktree');
    $application = 'apps/laravel';
    mkdir($primary.'/'.$application, 0755, true);
    file_put_contents($primary.'/'.$application.'/artisan', '<?php');
    (new Process(['git', 'init', '--initial-branch=main', $primary]))->mustRun();
    (new Process(['git', 'add', '.'], $primary))->mustRun();
    (new Process(['git', '-c', 'user.name=Harness Test', '-c', 'user.email=harness@example.invalid', 'commit', '--no-gpg-sign', '-m', 'Fixture'], $primary))->mustRun();
    harness_process(['init', '--global'], $primary.'/'.$application)->mustRun();
    file_put_contents($primary.'/'.$application.'/.ai-harness.config.global', "runtime=herd\nphp_version=8.4\nlocal_env.DB_PORT=3307\n");
    file_put_contents($primary.'/'.$application.'/.ai-harness.config.local', "local_env.MAIL_PORT=1026\n");
    (new Process(['git', 'worktree', 'add', '--detach', $worktree], $primary))->mustRun();
    $root = $worktree.'/'.$application;

    try {
        $loader = new ConfigLoader;
        $inherited = $loader->load($root);
        expect($inherited->runtime)->toBe(Runtime::Herd)
            ->and($inherited->phpVersion)->toBe('8.4')
            ->and($inherited->localEnvironment)->toBe(['DB_PORT' => '3307', 'MAIL_PORT' => '1026']);

        harness_process(['init', '--global'], $root)->mustRun();
        harness_process(['update'], $root)->mustRun();
        expect($root.'/.ai-harness.config.global')->not->toBeFile()
            ->and($root.'/.ai-harness.config')->not->toBeFile()
            ->and((new ConfigLoader)->load($root)->runtime)->toBe(Runtime::Herd);

        file_put_contents($root.'/.ai-harness.config.local', "runtime=native\nlocal_env.DB_PORT=3308\n");
        $overridden = $loader->load($root);
        expect($overridden->runtime)->toBe(Runtime::Native)
            ->and($overridden->localEnvironment)->toBe(['DB_PORT' => '3308', 'MAIL_PORT' => '1026']);
    } finally {
        (new Process(['git', 'worktree', 'remove', '--force', $worktree], $primary))->mustRun();
    }
});

foreach ([0600, 0640] as $permissions) {
    test('global initialization and updates preserve Claude local settings permissions '.decoct($permissions), function () use ($permissions): void {
        $root = temp_directory('harness-claude-local-permissions');
        (new Process(['git', 'init', $root]))->mustRun();
        mkdir($root.'/.claude');
        $path = $root.'/.claude/settings.local.json';
        file_put_contents($path, '{"env":{"PRIVATE_TOKEN":"fixture-secret"},"permissions":{"allow":["Read"]}}');
        chmod($path, $permissions);

        harness_process(['init', '--global'], $root)->mustRun();
        clearstatcache(true, $path);
        expect(fileperms($path) & 0777)->toBe($permissions);

        // Changing hooks forces another write rather than exercising only an idempotent no-op.
        file_put_contents($root.'/.ai-harness.config.global', "agents=claude\nworktrees=false\ncloud=false\n");
        harness_process(['update'], $root)->mustRun();
        clearstatcache(true, $path);
        expect(fileperms($path) & 0777)->toBe($permissions)
            ->and(file_get_contents($path))->toContain('fixture-secret', 'Read')
            ->and(file_get_contents($path))->not->toContain('ai-harness hook claude');
    });
}
