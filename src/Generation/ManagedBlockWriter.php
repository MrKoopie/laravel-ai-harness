<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Generation;

use RuntimeException;

/**
 * Writes package-owned blocks into otherwise user-owned text files.
 */
final readonly class ManagedBlockWriter
{
    /**
     * @param  non-empty-string  $marker
     */
    public function __construct(private string $marker) {}

    /**
     * Append or replace the managed block at the given path.
     */
    public function write(string $path, string $content): void
    {
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create directory [{$directory}].");
        }

        if (file_exists($path)) {
            $existing = file_get_contents($path);

            if ($existing === false) {
                throw new RuntimeException("Unable to read file [{$path}].");
            }
        } else {
            $existing = '';
        }

        $block = $this->block($path, $content);
        $pattern = $this->pattern($path);
        $matches = preg_match($pattern, $existing);

        if ($matches === false) {
            throw new RuntimeException("Unable to inspect managed block in file [{$path}].");
        }

        if ($matches === 1) {
            $updated = preg_replace_callback(
                $pattern,
                static fn (): string => $block,
                $existing,
                1,
            );

            if ($updated === null) {
                throw new RuntimeException("Unable to update managed block in file [{$path}].");
            }

            $this->writeFile($path, $this->withTrailingNewline($updated));

            return;
        }

        $unmarkedContent = trim($content);
        $contentOffset = $unmarkedContent === '' ? false : strpos($existing, $unmarkedContent);

        if ($contentOffset !== false) {
            $updated = substr_replace($existing, $block, $contentOffset, strlen($unmarkedContent));

            $this->writeFile($path, $this->withTrailingNewline($updated));

            return;
        }

        $separator = trim($existing) === '' ? '' : "\n\n";

        $this->writeFile($path, $this->withTrailingNewline(rtrim($existing).$separator.$block));
    }

    private function writeFile(string $path, string $content): void
    {
        if (file_put_contents($path, $content) !== strlen($content)) {
            throw new RuntimeException("Unable to write file [{$path}].");
        }
    }

    private function block(string $path, string $content): string
    {
        return sprintf(
            "%s\n%s\n%s",
            $this->startMarker($path),
            trim($content),
            $this->endMarker($path),
        );
    }

    private function pattern(string $path): string
    {
        return sprintf(
            '/%s.*?%s/s',
            preg_quote($this->startMarker($path), '/'),
            preg_quote($this->endMarker($path), '/'),
        );
    }

    private function startMarker(string $path): string
    {
        if ($this->usesHashComments($path)) {
            return "# {$this->marker}:start";
        }

        return "<!-- {$this->marker}:start -->";
    }

    private function endMarker(string $path): string
    {
        if ($this->usesHashComments($path)) {
            return "# {$this->marker}:end";
        }

        return "<!-- {$this->marker}:end -->";
    }

    private function withTrailingNewline(string $content): string
    {
        return str_ends_with($content, "\n") ? $content : $content."\n";
    }

    private function usesHashComments(string $path): bool
    {
        return basename($path) === '.gitignore' || str_ends_with($path, '.toml');
    }
}
