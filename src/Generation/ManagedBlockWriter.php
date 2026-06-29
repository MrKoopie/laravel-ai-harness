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

        $block = $this->block($content);
        $pattern = $this->pattern();
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

        $separator = trim($existing) === '' ? '' : "\n\n";

        $this->writeFile($path, $this->withTrailingNewline(rtrim($existing).$separator.$block));
    }

    private function writeFile(string $path, string $content): void
    {
        if (file_put_contents($path, $content) !== strlen($content)) {
            throw new RuntimeException("Unable to write file [{$path}].");
        }
    }

    private function block(string $content): string
    {
        return sprintf(
            "<!-- %s:start -->\n%s\n<!-- %s:end -->",
            $this->marker,
            trim($content),
            $this->marker,
        );
    }

    private function pattern(): string
    {
        return sprintf(
            '/<!-- %s:start -->.*?<!-- %s:end -->/s',
            preg_quote($this->marker, '/'),
            preg_quote($this->marker, '/'),
        );
    }

    private function withTrailingNewline(string $content): string
    {
        return str_ends_with($content, "\n") ? $content : $content."\n";
    }
}
