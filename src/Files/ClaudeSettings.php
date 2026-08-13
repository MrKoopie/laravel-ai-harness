<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Files;

use JsonException;

final readonly class ClaudeSettings
{
    private const MAX_FILE_SIZE = 1_048_576;

    public function __construct(private SafeWriter $writer) {}

    public function sync(string $root, bool $enabled): void
    {
        $path = $root.'/.claude/settings.json';
        $this->writer->assertSafePath($root, '.claude/settings.json');

        if (! $enabled && ! is_file($path)) {
            return;
        }

        if (is_link($path)) {
            throw new FileException("Refusing to edit symbolic link [{$path}].");
        }

        $settings = $this->read($path);
        $hooks = $settings['hooks'] ?? [];

        if (! is_array($hooks)) {
            throw new FileException("Claude hooks must be an object in [{$path}].");
        }

        foreach ($hooks as $event => $groups) {
            if (! is_string($event) || ! is_array($groups) || ! array_is_list($groups)) {
                throw new FileException("Claude hook event [{$event}] must be an array in [{$path}].");
            }

            $hooks[$event] = $this->withoutHarnessHooks($groups);

            if ($hooks[$event] === []) {
                unset($hooks[$event]);
            }
        }

        if ($enabled) {
            $hooks['SessionStart'][] = $this->group(null, 'session-start', 'Preparing the Laravel AI Harness worktree');
            $hooks['PostToolUse'][] = $this->group('EnterWorktree', 'enter-worktree', 'Preparing the Laravel AI Harness worktree');
            $hooks['PreToolUse'][] = $this->group('ExitWorktree', 'exit-worktree', 'Cleaning the Laravel AI Harness worktree');
            $hooks['WorktreeRemove'][] = $this->group(null, 'worktree-remove', 'Cleaning the Laravel AI Harness worktree');
        }

        if ($hooks === []) {
            unset($settings['hooks']);
        } else {
            $settings['hooks'] = $hooks;
        }

        try {
            $encoded = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new FileException('Unable to encode Claude settings: '.$exception->getMessage(), previous: $exception);
        }

        $this->writer->write($root, '.claude/settings.json', $encoded);
    }

    /**
     * @return array<string, mixed>
     */
    private function read(string $path): array
    {
        if (! is_file($path)) {
            return ['$schema' => 'https://json.schemastore.org/claude-code-settings.json'];
        }

        $size = filesize($path);

        if ($size === false || $size > self::MAX_FILE_SIZE) {
            throw new FileException("Claude settings [{$path}] exceed 1 MiB or cannot be read.");
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new FileException("Unable to read Claude settings [{$path}].");
        }

        try {
            $settings = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new FileException('Invalid Claude settings JSON: '.$exception->getMessage(), previous: $exception);
        }

        if (! is_array($settings) || array_is_list($settings)) {
            throw new FileException("Claude settings [{$path}] must contain a JSON object.");
        }

        return $settings;
    }

    /**
     * @param  list<mixed>  $groups
     * @return list<mixed>
     */
    private function withoutHarnessHooks(array $groups): array
    {
        $filtered = [];

        foreach ($groups as $group) {
            if (! is_array($group)) {
                $filtered[] = $group;

                continue;
            }

            $commands = $group['hooks'] ?? null;

            if (! is_array($commands)) {
                $filtered[] = $group;

                continue;
            }

            $group['hooks'] = array_values(array_filter(
                $commands,
                static fn (mixed $hook): bool => ! is_array($hook)
                    || ! is_string($hook['command'] ?? null)
                    || ! str_contains($hook['command'], '.ai-harness" hook claude '),
            ));

            if ($group['hooks'] !== []) {
                $filtered[] = $group;
            }
        }

        return $filtered;
    }

    /**
     * @return array<string, mixed>
     */
    private function group(?string $matcher, string $event, string $status): array
    {
        $group = [
            'hooks' => [[
                'type' => 'command',
                'command' => '"${CLAUDE_PROJECT_DIR}/.ai-harness" hook claude '.$event,
                'timeout' => 600,
                'statusMessage' => $status,
            ]],
        ];

        if ($matcher !== null) {
            $group = ['matcher' => $matcher] + $group;
        }

        return $group;
    }
}
