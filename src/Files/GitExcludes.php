<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Files;

use Symfony\Component\Process\Process;

final readonly class GitExcludes
{
    public function __construct(private SafeWriter $writer) {}

    /**
     * Exclude personal integration files without changing the index or shared ignore rules.
     *
     * @param  list<string>  $paths
     * @return list<string> Tracked launchers that must be left untouched.
     */
    public function sync(string $root, array $paths): array
    {
        $metadata = $this->git($root, ['rev-parse', '--path-format=absolute', '--git-common-dir', '--show-toplevel']);
        $directories = explode("\n", trim($metadata));
        $common = realpath($directories[0]);
        $repository = realpath($directories[1] ?? '');

        if ($common === false || $repository === false || ! is_dir($common)
            || ($root !== $repository && ! str_starts_with($root, $repository.'/'))) {
            throw new FileException('Global installation requires a Git working tree with resolvable local excludes.');
        }

        $tracked = array_values(array_filter(explode("\0", $this->git($root, ['ls-files', '--cached', '-z', '--', ...$paths]))));

        foreach ($tracked as $path) {
            if (! in_array($path, ['.ai-harness', '.ai-harness-cloud'], true)) {
                throw new FileException("Global installation cannot keep tracked file [{$path}] private; use a project installation or untrack that personal file first.");
            }
        }

        $relative = ltrim(substr($root, strlen($repository)), '/');
        $prefix = $relative === '' ? '' : $relative.'/';
        $patterns = array_map(fn (string $path): string => '/'.$this->escape($prefix.$path), $paths);
        $this->writer->assertSafePath($common, 'info/exclude');

        if (is_link($common.'/info/exclude')) {
            throw new FileException('Refusing to edit symbolic link [info/exclude].');
        }

        // A common exclude file is shared by worktrees and may cover multiple nested applications.
        $existing = is_file($common.'/info/exclude') ? file_get_contents($common.'/info/exclude') : '';

        if ($existing === false) {
            throw new FileException('Unable to read Git info/exclude.');
        }

        if (preg_match('/# ai-harness:start\R(.*?)\R# ai-harness:end/s', $existing, $matches) === 1) {
            $patterns = [...explode("\n", $matches[1]), ...$patterns];
        }

        $this->writer->managedBlock($common, 'info/exclude', implode("\n", array_unique($patterns)));

        $ignored = explode("\0", $this->git($root, ['check-ignore', '--no-index', '-z', '--stdin'], allowNoMatches: true, input: implode("\0", $paths)."\0"));

        foreach (array_diff($paths, $tracked, $ignored) as $path) {
            throw new FileException("Shared Git ignore rules prevent keeping [{$path}] private through info/exclude; resolve the conflicting rule before initializing global mode.");
        }

        return $tracked;
    }

    /**
     * Run only read-only Git metadata queries with no inherited repository overrides.
     *
     * @param  list<string>  $arguments
     */
    private function git(string $root, array $arguments, bool $allowNoMatches = false, ?string $input = null): string
    {
        $process = new Process(['git', ...$arguments], $root, [
            'GIT_DIR' => false,
            'GIT_WORK_TREE' => false,
            'GIT_COMMON_DIR' => false,
            'GIT_INDEX_FILE' => false,
            'GIT_OPTIONAL_LOCKS' => '0',
        ]);
        $process->setTimeout(10);
        $process->setInput($input);
        $process->run();

        if (! $process->isSuccessful() && ! ($allowNoMatches && $process->getExitCode() === 1)) {
            throw new FileException('Global installation requires Git and a Git working tree: '.trim($process->getErrorOutput()));
        }

        return $process->getOutput();
    }

    /** Escape literal project paths for Git ignore syntax. */
    private function escape(string $path): string
    {
        if (str_contains($path, "\n") || str_contains($path, "\r")) {
            throw new FileException('Global installation does not support project paths containing line breaks.');
        }

        return str_replace(['\\', '*', '?', '[', ']', ' '], ['\\\\', '\\*', '\\?', '\\[', '\\]', '\\ '], $path);
    }
}
