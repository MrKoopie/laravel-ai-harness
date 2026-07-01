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

        $existing = $this->removeConflictingUnmanagedContent($path, $existing, $content);
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
        $unmarkedMatches = [];
        $contentOffset = false;

        if ($unmarkedContent !== '') {
            $unmarkedPattern = sprintf('/%s/s', str_replace("\n", '\R', preg_quote($unmarkedContent, '/')));
            $unmarkedMatch = preg_match($unmarkedPattern, $existing, $unmarkedMatches, PREG_OFFSET_CAPTURE);

            if ($unmarkedMatch === false) {
                throw new RuntimeException("Unable to inspect unmarked managed block in file [{$path}].");
            }

            if ($unmarkedMatch === 1) {
                $contentOffset = $unmarkedMatches[0][1];
            }
        }

        if ($contentOffset !== false) {
            $updated = substr_replace($existing, $block, $contentOffset, strlen($unmarkedMatches[0][0]));

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

    private function removeConflictingUnmanagedContent(string $path, string $existing, string $content): string
    {
        if (! $this->isCodexConfig($path) || ! str_contains($content, '[mcp_servers.laravel-boost]')) {
            return $existing;
        }

        return $this->removeTomlTableOutsideManagedBlock($path, $existing, 'mcp_servers.laravel-boost');
    }

    private function removeTomlTableOutsideManagedBlock(string $path, string $content, string $table): string
    {
        $lines = preg_split('/\R/', $content);

        if ($lines === false) {
            return $content;
        }

        $result = [];
        $insideManagedBlock = false;
        $skippingTable = false;
        $startMarker = $this->startMarker($path);
        $endMarker = $this->endMarker($path);

        foreach ($lines as $line) {
            if ($skippingTable && ! $this->isTomlTableHeader($line)) {
                continue;
            }

            if ($skippingTable) {
                $skippingTable = false;
            }

            if (trim($line) === $startMarker) {
                $insideManagedBlock = true;
                $result[] = $line;

                continue;
            }

            if (! $insideManagedBlock && $this->isTomlTableHeader($line, $table)) {
                $skippingTable = true;

                continue;
            }

            $result[] = $line;

            if (trim($line) === $endMarker) {
                $insideManagedBlock = false;
            }
        }

        $updated = implode("\n", $result);
        $updated = preg_replace("/\n{3,}/", "\n\n", $updated) ?? $updated;

        return rtrim($updated);
    }

    private function isTomlTableHeader(string $line, ?string $table = null): bool
    {
        if ($table === null) {
            return preg_match('/^\s*\[{1,2}[^\]]+\]{1,2}\s*(?:#.*)?$/', $line) === 1;
        }

        return preg_match('/^\s*\['.preg_quote($table, '/').'\]\s*(?:#.*)?$/', $line) === 1;
    }

    private function isCodexConfig(string $path): bool
    {
        $path = str_replace('\\', '/', $path);

        return $path === '.codex/config.toml' || str_ends_with($path, '/.codex/config.toml');
    }

    private function usesHashComments(string $path): bool
    {
        return basename($path) === '.gitignore' || str_ends_with($path, '.toml');
    }
}
