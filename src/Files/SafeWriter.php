<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Files;

final class SafeWriter
{
    public function write(string $root, string $relativePath, string $contents, bool $executable = false): void
    {
        $target = $this->target($root, $relativePath);
        $directory = dirname($target);

        $this->assertSafePath($root, $relativePath);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new FileException("Unable to create directory [{$directory}].");
        }

        $this->assertSafeParent($root, $directory);

        if (is_link($target)) {
            throw new FileException("Refusing to overwrite symbolic link [{$target}].");
        }

        $temporary = tempnam($directory, '.ai-harness-');

        if ($temporary === false) {
            throw new FileException("Unable to create a temporary file in [{$directory}].");
        }

        try {
            $normalized = str_ends_with($contents, "\n") ? $contents : $contents."\n";

            if (file_put_contents($temporary, $normalized, LOCK_EX) !== strlen($normalized)) {
                throw new FileException("Unable to write temporary file [{$temporary}].");
            }

            if (! chmod($temporary, $executable ? 0755 : 0644)) {
                throw new FileException("Unable to set permissions on [{$temporary}].");
            }

            if (! rename($temporary, $target)) {
                throw new FileException("Unable to replace file [{$target}].");
            }
        } finally {
            if (file_exists($temporary)) {
                unlink($temporary);
            }
        }
    }

    public function managedBlock(string $root, string $relativePath, string $block): void
    {
        $target = $this->target($root, $relativePath);
        $this->assertSafePath($root, $relativePath);
        $hashComments = basename($relativePath) === '.gitignore';
        $start = $hashComments ? '# ai-harness:start' : '<!-- ai-harness:start -->';
        $end = $hashComments ? '# ai-harness:end' : '<!-- ai-harness:end -->';
        $managed = $start."\n".trim($block)."\n".$end;
        $existing = '';

        if (is_link($target)) {
            throw new FileException("Refusing to edit symbolic link [{$target}].");
        }

        if (is_file($target)) {
            $contents = file_get_contents($target);

            if ($contents === false) {
                throw new FileException("Unable to read [{$target}].");
            }

            $existing = $contents;
        }

        $startCount = substr_count($existing, $start);
        $endCount = substr_count($existing, $end);

        if ($startCount !== $endCount || $startCount > 1) {
            throw new FileException("Managed block markers are malformed in [{$target}].");
        }

        if ($startCount === 1) {
            $pattern = '/'.preg_quote($start, '/').'.*?'.preg_quote($end, '/').'/s';
            $updated = preg_replace($pattern, $managed, $existing, 1, $count);

            if ($updated === null || $count !== 1) {
                throw new FileException("Unable to update the managed block in [{$target}].");
            }
        } else {
            $updated = rtrim($existing);
            $updated .= $updated === '' ? $managed : "\n\n".$managed;
        }

        $this->write($root, $relativePath, $updated);
    }

    public function removeManagedBlock(string $root, string $relativePath): void
    {
        $target = $this->target($root, $relativePath);
        $this->assertSafePath($root, $relativePath);

        if (is_link($target)) {
            throw new FileException("Refusing to edit symbolic link [{$target}].");
        }

        if (! is_file($target)) {
            return;
        }

        $contents = file_get_contents($target);

        if ($contents === false) {
            throw new FileException("Unable to read [{$target}].");
        }

        $hashComments = basename($relativePath) === '.gitignore';
        $start = $hashComments ? '# ai-harness:start' : '<!-- ai-harness:start -->';
        $end = $hashComments ? '# ai-harness:end' : '<!-- ai-harness:end -->';
        $startCount = substr_count($contents, $start);
        $endCount = substr_count($contents, $end);

        if ($startCount === 0 && $endCount === 0) {
            return;
        }

        if ($startCount !== 1 || $endCount !== 1) {
            throw new FileException("Managed block markers are malformed in [{$target}].");
        }

        $pattern = '/\s*'.preg_quote($start, '/').'.*?'.preg_quote($end, '/').'\s*/s';
        $updated = preg_replace($pattern, "\n", $contents, 1, $count);

        if ($updated === null || $count !== 1) {
            throw new FileException("Unable to remove the managed block in [{$target}].");
        }

        if (trim($updated) === '') {
            if (! unlink($target)) {
                throw new FileException("Unable to remove empty managed file [{$target}].");
            }

            return;
        }

        $this->write($root, $relativePath, trim($updated));
    }

    public function removeOwnedFile(string $root, string $relativePath, string $expectedContents): void
    {
        $target = $this->target($root, $relativePath);
        $this->assertSafePath($root, $relativePath);

        if (is_link($target)) {
            throw new FileException("Refusing to remove symbolic link [{$target}].");
        }

        if (! is_file($target)) {
            return;
        }

        $contents = file_get_contents($target);

        if ($contents === false) {
            throw new FileException("Unable to read [{$target}].");
        }

        if (rtrim($contents) !== rtrim($expectedContents)) {
            return;
        }

        if (! unlink($target)) {
            throw new FileException("Unable to remove package-owned file [{$target}].");
        }
    }

    public function assertSafePath(string $root, string $relativePath): void
    {
        $target = $this->target($root, $relativePath);
        $directory = dirname($target);

        $this->assertSafeExistingAncestor($root, $directory);

        if (is_dir($directory)) {
            $this->assertSafeParent($root, $directory);
        }
    }

    private function target(string $root, string $relativePath): string
    {
        if ($relativePath === '' || str_contains($relativePath, "\0") || str_starts_with($relativePath, DIRECTORY_SEPARATOR)) {
            throw new FileException("Invalid managed path [{$relativePath}].");
        }

        $segments = preg_split('#[\\\\/]#', $relativePath);

        if ($segments === false || in_array('..', $segments, true)) {
            throw new FileException("Managed path escapes the project root [{$relativePath}].");
        }

        return $root.DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath);
    }

    private function assertSafeParent(string $root, string $directory): void
    {
        $resolvedRoot = realpath($root);
        $resolvedDirectory = realpath($directory);

        if ($resolvedRoot === false || $resolvedDirectory === false) {
            throw new FileException('Unable to resolve a managed file path.');
        }

        if ($resolvedDirectory !== $resolvedRoot && ! str_starts_with($resolvedDirectory, $resolvedRoot.DIRECTORY_SEPARATOR)) {
            throw new FileException("Managed directory [{$resolvedDirectory}] escapes project root [{$resolvedRoot}].");
        }
    }

    private function assertSafeExistingAncestor(string $root, string $directory): void
    {
        $ancestor = $directory;

        while (! file_exists($ancestor) && ! is_link($ancestor)) {
            $parent = dirname($ancestor);

            if ($parent === $ancestor) {
                throw new FileException("Unable to locate an existing ancestor for [{$directory}].");
            }

            $ancestor = $parent;
        }

        $resolvedRoot = realpath($root);
        $resolvedAncestor = realpath($ancestor);

        if ($resolvedRoot === false || $resolvedAncestor === false) {
            throw new FileException('Unable to resolve a managed file ancestor.');
        }

        if ($resolvedAncestor !== $resolvedRoot && ! str_starts_with($resolvedAncestor, $resolvedRoot.DIRECTORY_SEPARATOR)) {
            throw new FileException("Managed path ancestor [{$resolvedAncestor}] escapes project root [{$resolvedRoot}].");
        }
    }
}
