<?php

declare(strict_types=1);

namespace Yourwebhoster\LaravelAiHarness\Generation;

/**
 * Performs lightweight placeholder replacement for package stubs.
 */
final readonly class TemplateRenderer
{
    /**
     * Render `{{ key }}` and `{{key}}` placeholders with scalar variables.
     *
     * @param  array<string, scalar|null>  $variables
     */
    public function render(string $template, array $variables): string
    {
        $replacements = [];

        foreach ($variables as $key => $value) {
            $replacements['{{ '.$key.' }}'] = (string) $value;
            $replacements['{{'.$key.'}}'] = (string) $value;
        }

        return strtr($template, $replacements);
    }
}
