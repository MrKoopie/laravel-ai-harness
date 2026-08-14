<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Console;

use JsonException;
use MrKoopie\LaravelAiHarness\Config\ConfigLoader;
use MrKoopie\LaravelAiHarness\Environment\EnvironmentManager;
use MrKoopie\LaravelAiHarness\Support\ProjectPath;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'hook', description: 'Handle a native coding-agent lifecycle event')]
final class HookCommand extends Command
{
    private const MAX_PAYLOAD_SIZE = 1_048_576;

    public function __construct(
        private readonly ConfigLoader $configLoader,
        private readonly EnvironmentManager $environment,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('agent', InputArgument::REQUIRED, 'Agent name')
            ->addArgument('event', InputArgument::REQUIRED, 'Lifecycle event');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getArgument('agent') !== 'claude') {
            throw new RuntimeException('Only Claude currently sends lifecycle events to the hook command.');
        }

        $event = $input->getArgument('event');

        if (! is_string($event) || ! in_array($event, ['session-start', 'enter-worktree', 'exit-worktree', 'worktree-remove'], true)) {
            throw new RuntimeException('Unsupported Claude lifecycle event.');
        }

        $payload = $this->payload();
        $path = $this->targetPath($event, $payload);

        if ($path === null) {
            $output->writeln('<comment>Hook event did not identify a worktree; nothing to do.</comment>');

            return self::SUCCESS;
        }

        $root = ProjectPath::resolve($path);

        try {
            $this->assertClaudeWorktree($root);
        } catch (RuntimeException $exception) {
            $output->writeln('<error>'.$exception->getMessage().'</error>');

            return self::FAILURE;
        }

        $config = $this->configLoader->load($root);

        if (! $config->worktrees) {
            $output->writeln('<info>Worktree automation is disabled.</info>');

            return self::SUCCESS;
        }

        return in_array($event, ['exit-worktree', 'worktree-remove'], true)
            ? $this->environment->cleanup($root, $output)
            : $this->environment->setup($root, $output);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        $contents = stream_get_contents(STDIN, self::MAX_PAYLOAD_SIZE + 1);

        if ($contents === false) {
            throw new RuntimeException('Unable to read Claude hook payload.');
        }

        if (strlen($contents) > self::MAX_PAYLOAD_SIZE) {
            throw new RuntimeException('Claude hook payload exceeds 1 MiB.');
        }

        if (trim($contents) === '') {
            return [];
        }

        try {
            $payload = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Invalid Claude hook JSON: '.$exception->getMessage(), previous: $exception);
        }

        if (! is_array($payload) || array_is_list($payload)) {
            throw new RuntimeException('Claude hook payload must be a JSON object.');
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function targetPath(string $event, array $payload): ?string
    {
        $path = match ($event) {
            'enter-worktree' => $this->nestedString($payload, 'tool_response', 'worktreePath')
                ?? $this->nestedString($payload, 'tool_response', 'worktree_path')
                ?? $this->nestedString($payload, 'tool_response', 'path')
                ?? $this->nestedString($payload, 'toolResponse', 'worktreePath')
                ?? $this->nestedString($payload, 'tool_input', 'path')
                ?? $this->nestedString($payload, 'worktree_path')
                ?? $this->nestedString($payload, 'cwd'),
            'exit-worktree' => $this->nestedString($payload, 'cwd'),
            'worktree-remove' => $this->nestedString($payload, 'worktree_path'),
            'session-start' => $this->nestedString($payload, 'cwd'),
            default => null,
        };

        if ($path === null || $path === '') {
            return null;
        }

        if ($event === 'session-start') {
            $normalized = str_replace('\\', '/', $path);

            if (! str_contains($normalized, '/.claude/worktrees/')) {
                return null;
            }
        }

        return $path;
    }

    private function assertClaudeWorktree(string $root): void
    {
        $projectDirectory = getenv('CLAUDE_PROJECT_DIR');

        if (! is_string($projectDirectory) || $projectDirectory === '') {
            throw new RuntimeException('CLAUDE_PROJECT_DIR is required for Claude lifecycle hooks.');
        }

        $projectRoot = ProjectPath::resolve($projectDirectory);
        $normalizedRoot = str_replace('\\', '/', $root);
        $normalizedProject = str_replace('\\', '/', $projectRoot);
        $projectIsWorktree = str_contains($normalizedProject, '/.claude/worktrees/');
        $isCurrentWorktree = $projectIsWorktree && $normalizedRoot === $normalizedProject;
        $isProjectWorktree = str_starts_with($normalizedRoot, $normalizedProject.'/.claude/worktrees/');

        if (! $isCurrentWorktree && ! $isProjectWorktree) {
            throw new RuntimeException("Claude hook target [{$root}] is outside the project worktree directory [{$projectRoot}].");
        }
    }

    /** @param array<string, mixed> $payload */
    private function nestedString(array $payload, string ...$segments): ?string
    {
        $value = $payload;

        foreach ($segments as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        return is_string($value) ? $value : null;
    }
}
