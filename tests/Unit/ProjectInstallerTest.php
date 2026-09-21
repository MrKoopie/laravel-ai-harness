<?php

declare(strict_types=1);

use MrKoopie\LaravelAiHarness\Config\ConfigLoader;
use MrKoopie\LaravelAiHarness\Files\ClaudeSettings;
use MrKoopie\LaravelAiHarness\Files\FileException;
use MrKoopie\LaravelAiHarness\Files\ProjectInstaller;
use MrKoopie\LaravelAiHarness\Files\SafeWriter;

test('project installation writes only the thin bootstrap and native agent files', function (): void {
    $root = temp_directory('harness-install');
    mkdir($root.'/.claude', 0755, true);
    file_put_contents($root.'/AGENTS.md', "User-owned Codex guidance.\n");
    file_put_contents($root.'/CLAUDE.md', "User-owned Claude guidance.\n");
    file_put_contents($root.'/.claude/settings.json', json_encode([
        'permissions' => ['allow' => ['Read']],
        'hooks' => [
            'PostToolUse' => [[
                'matcher' => 'Write',
                'hooks' => [['type' => 'command', 'command' => 'echo custom']],
            ]],
        ],
    ], JSON_PRETTY_PRINT));

    $writer = new SafeWriter;
    $installer = new ProjectInstaller($writer, new ClaudeSettings($writer));

    expect($installer->ensureConfig($root))->toBeTrue();
    $config = (new ConfigLoader)->load($root);
    $first = $installer->install($root, $config);
    $second = $installer->install($root, $config);

    expect($first)->toBe($second)
        ->and($root.'/.ai-harness')->toBeFile()
        ->and(is_executable($root.'/.ai-harness'))->toBeTrue()
        ->and(file_get_contents($root.'/.ai-harness'))->toContain('exec "${harness_binary}" "$@"')
        ->and(substr_count((string) file_get_contents($root.'/AGENTS.md'), '<!-- ai-harness:start -->'))->toBe(1)
        ->and(file_get_contents($root.'/AGENTS.md'))->toStartWith('User-owned Codex guidance.')
        ->and(substr_count((string) file_get_contents($root.'/CLAUDE.md'), '<!-- ai-harness:start -->'))->toBe(1)
        ->and($root.'/.codex/environments/environment.toml')->toBeFile()
        ->and((string) file_get_contents($root.'/.gitignore'))->toContain('!/.codex/environments/environment.toml')
        ->and((string) file_get_contents($root.'/.gitignore'))->toContain('.env.testing')
        ->and($root.'/.claude/settings.json')->toBeFile()
        ->and($root.'/.dev/bin/ai-harness')->not->toBeFile()
        ->and($root.'/.codex/scripts/local-environment.sh')->not->toBeFile()
        ->and($root.'/.claude/scripts/worktree-up.sh')->not->toBeFile();

    $settings = json_decode((string) file_get_contents($root.'/.claude/settings.json'), true, flags: JSON_THROW_ON_ERROR);
    $encoded = json_encode($settings, JSON_THROW_ON_ERROR);
    $settingsShape = json_decode((string) file_get_contents($root.'/.claude/settings.json'), flags: JSON_THROW_ON_ERROR);

    expect($settings['permissions'])->toBe(['allow' => ['Read']])
        ->and($encoded)->toContain('echo custom')
        ->and(substr_count($encoded, 'hook claude session-start'))->toBe(1)
        ->and(substr_count($encoded, 'hook claude enter-worktree'))->toBe(1)
        ->and(substr_count($encoded, 'hook claude exit-worktree'))->toBe(1)
        ->and(substr_count($encoded, 'hook claude worktree-remove'))->toBe(1)
        ->and($settingsShape->permissions->allow)->toBeArray()
        ->and($settingsShape->hooks->SessionStart)->toBeArray()
        ->and($settingsShape->hooks->SessionStart[0]->hooks)->toBeArray();
});

test('worktree false removes only harness-owned Claude hooks', function (): void {
    $root = temp_directory('harness-hooks-off');
    mkdir($root.'/.claude', 0755, true);
    file_put_contents($root.'/.ai-harness.config', "agents=codex,claude\nworktrees=true\n");
    file_put_contents($root.'/.claude/settings.json', json_encode([
        'hooks' => [
            'SessionStart' => [[
                'hooks' => [['type' => 'command', 'command' => 'echo custom']],
            ]],
        ],
    ], JSON_PRETTY_PRINT));

    $writer = new SafeWriter;
    $installer = new ProjectInstaller($writer, new ClaudeSettings($writer));
    $installer->install($root, (new ConfigLoader)->load($root));
    file_put_contents($root.'/.ai-harness.config.local', "worktrees=false\n");
    $installer->install($root, (new ConfigLoader)->load($root));

    $settings = (string) file_get_contents($root.'/.claude/settings.json');

    expect($settings)->toContain('echo custom')
        ->and(str_contains($settings, 'hook claude'))->toBeFalse();
});

test('managed project files never follow symbolic links', function (): void {
    $root = temp_directory('harness-symlink');
    $outside = temp_file('harness-outside');
    symlink($outside, $root.'/AGENTS.md');

    $writer = new SafeWriter;

    expect(fn () => $writer->managedBlock($root, 'AGENTS.md', 'managed'))
        ->toThrow(FileException::class, 'Refusing to edit symbolic link');
});

test('managed blocks preserve literal replacement characters', function (): void {
    $root = temp_directory('harness-managed-literals');
    $writer = new SafeWriter;
    $block = 'Literal \\1 and $1 stay unchanged.';

    $writer->managedBlock($root, 'AGENTS.md', $block);
    $writer->managedBlock($root, 'AGENTS.md', $block);

    expect(file_get_contents($root.'/AGENTS.md'))->toContain($block);
});

test('an empty Claude settings object remains a valid object when hooks are disabled', function (): void {
    $root = temp_directory('harness-empty-claude-settings');
    mkdir($root.'/.claude', 0755, true);
    file_put_contents($root.'/.ai-harness.config', "agents=\nworktrees=false\n");
    file_put_contents($root.'/.claude/settings.json', "{}\n");

    $writer = new SafeWriter;
    $installer = new ProjectInstaller($writer, new ClaudeSettings($writer));
    $installer->install($root, (new ConfigLoader)->load($root));

    expect(file_get_contents($root.'/.claude/settings.json'))->toBe("{}\n");
});

test('disabling agents removes only package-owned integration content', function (): void {
    $root = temp_directory('harness-agents-off');
    file_put_contents($root.'/AGENTS.md', "User Codex rule.\n");
    file_put_contents($root.'/CLAUDE.md', "User Claude rule.\n");
    file_put_contents($root.'/.ai-harness.config', "agents=codex,claude\nworktrees=true\n");

    $writer = new SafeWriter;
    $installer = new ProjectInstaller($writer, new ClaudeSettings($writer));
    $installer->install($root, (new ConfigLoader)->load($root));
    file_put_contents($root.'/.ai-harness.config.local', "agents=\nworktrees=false\n");
    $installer->install($root, (new ConfigLoader)->load($root));

    expect(file_get_contents($root.'/AGENTS.md'))->toBe("User Codex rule.\n")
        ->and(file_get_contents($root.'/CLAUDE.md'))->toBe("User Claude rule.\n")
        ->and($root.'/.codex/environments/environment.toml')->not->toBeFile()
        ->and(str_contains((string) file_get_contents($root.'/.claude/settings.json'), 'hook claude'))->toBeFalse();
});

test('managed directory creation cannot traverse an intermediate symbolic link', function (): void {
    $root = temp_directory('harness-parent-symlink');
    $outside = temp_directory('harness-parent-outside');
    symlink($outside, $root.'/.codex');

    $writer = new SafeWriter;

    expect(fn () => $writer->write($root, '.codex/environments/environment.toml', 'managed'))
        ->toThrow(FileException::class, 'escapes project root')
        ->and(is_dir($outside.'/environments'))->toBeFalse();
});
