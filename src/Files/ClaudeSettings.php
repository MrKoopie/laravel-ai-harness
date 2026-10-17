<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Files;

use JsonException;
use MrKoopie\LaravelAiHarness\Config\ConfigLoader;

final readonly class ClaudeSettings
{
    private const MAX_FILE_SIZE = 1_048_576;

    /** Create a Claude settings manager backed by safe writes. */
    public function __construct(private SafeWriter $writer) {}

    /** Add or remove harness-owned Claude lifecycle hooks. */
    public function sync(string $root, bool $enabled, bool $cloud = false, bool $local = false): void
    {
        $relative = $local ? '.claude/settings.local.json' : '.claude/settings.json';
        $path = $root.'/'.$relative;
        $this->writer->assertSafePath($root, $relative);

        if (! $enabled && ! $cloud && ! is_file($path)) {
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

        if ($enabled || $cloud) {
            $hooks['SessionStart'][] = $this->group(null, 'session-start', 'Preparing the Laravel AI Harness environment', $local);
        }

        if ($cloud) {
            $group = $this->group(null, 'session-end', 'Cleaning cloud testing databases', $local);
            $group['hooks'][0]['timeout'] = 60;
            $hooks['SessionEnd'][] = $group;
        }

        if ($enabled) {
            $hooks['PostToolUse'][] = $this->group('EnterWorktree', 'enter-worktree', 'Preparing the Laravel AI Harness worktree', $local);
            $hooks['PreToolUse'][] = $this->group('ExitWorktree', 'exit-worktree', 'Cleaning the Laravel AI Harness worktree', $local);
            $hooks['WorktreeRemove'][] = $this->group(null, 'worktree-remove', 'Cleaning the Laravel AI Harness worktree', $local);
        }

        if ($hooks === []) {
            unset($settings['hooks']);
        } else {
            $settings['hooks'] = $hooks;
        }

        try {
            $encoded = json_encode((object) $settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new FileException('Unable to encode Claude settings: '.$exception->getMessage(), previous: $exception);
        }

        $normalized = str_ends_with($encoded, "\n") ? $encoded : $encoded."\n";

        if (is_file($path) && file_get_contents($path) === $normalized) {
            return;
        }

        $this->writer->write($root, $relative, $encoded);
    }

    /** Merge harness variables while preserving unrelated user environment and permissions.
     * @param  array<string, string>  $variables
     */
    public function environment(string $root, array $variables, bool $local = false, bool $write = true): void
    {
        $this->updateEnvironment($root, $variables, $local, $write);
    }

    /** Clear only harness-owned variables when this integration is explicitly deselected. */
    public function clearEnvironment(string $root, bool $local = false, bool $write = true): void
    {
        $path = $local ? '.claude/settings.local.json' : '.claude/settings.json';
        if (! file_exists($root.'/'.$path) && ! is_link($root.'/'.$path)) {
            return;
        }
        $this->updateEnvironment($root, [], $local, $write, clear: true);
    }

    /** @param array<string, string> $variables */
    private function updateEnvironment(string $root, array $variables, bool $local, bool $write, bool $clear = false): void
    {
        $relative = $local ? '.claude/settings.local.json' : '.claude/settings.json';
        $this->writer->assertSafePath($root, $relative);
        if (is_link($root.'/'.$relative)) {
            throw new FileException('Refusing to edit symbolic link at '.$relative.'.');
        }
        $settings = $this->read($root.'/'.$relative);
        $environment = $settings['env'] ?? [];
        if (! is_array($environment) || ($environment !== [] && array_is_list($environment))) {
            throw new FileException('Claude env must be an object in '.$relative.'.');
        }
        if ($clear) {
            foreach (ConfigLoader::environmentNames() as $name) {
                unset($environment[$name]);
            }
        }
        unset($environment['AI_HARNESS_SERVICE_MANAGER']);
        $settings['env'] = (object) [...$environment, ...$variables];
        if ($write) {
            $this->writer->write($root, $relative, json_encode((object) $settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        }
    }

    /**
     * Read and validate Claude's project settings.
     *
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

        if (! is_array($settings) || ($settings !== [] && array_is_list($settings))) {
            throw new FileException("Claude settings [{$path}] must contain a JSON object.");
        }

        return $settings;
    }

    /**
     * Remove harness-owned commands while preserving other hook entries.
     *
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
                    || ! self::isHarnessCommand($hook['command']),
            ));

            if ($group['hooks'] !== []) {
                $filtered[] = $group;
            }
        }

        return $filtered;
    }

    /** Identify current commands and the exact commands installed by v0.1. */
    private static function isHarnessCommand(string $command): bool
    {
        if (str_contains($command, '.ai-harness" hook claude ') || str_starts_with($command, 'ai-harness hook claude ')) {
            return true;
        }

        return in_array($command, [
            '"$CLAUDE_PROJECT_DIR/.claude/scripts/worktree-up.sh"',
            '"$CLAUDE_PROJECT_DIR/.claude/scripts/worktree-down.sh"',
            '"$CLAUDE_PROJECT_DIR/.claude/scripts/worktree-up.sh" && "$CLAUDE_PROJECT_DIR/.dev/bin/ai-harness" ai-harness:doctor',
        ], true);
    }

    /**
     * Build one Claude hook group for a harness lifecycle event.
     *
     * @return array<string, mixed>
     */
    private function group(?string $matcher, string $event, string $status, bool $local = false): array
    {
        $group = [
            'hooks' => [[
                'type' => 'command',
                'command' => ($local ? 'ai-harness hook claude ' : '"${CLAUDE_PROJECT_DIR}/.ai-harness" hook claude ').$event,
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
