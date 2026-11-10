<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Support;

use MrKoopie\LaravelAiHarness\Files\FileException;

final class EnvironmentSource
{
    /** Resolve the environment read by setup and its preflight checks. */
    public static function forProject(string $root, bool $usePrimary = false): string
    {
        if (is_file($root.'/.env') || is_link($root.'/.env')) {
            return $root.'/.env';
        }

        if ($usePrimary) {
            $primary = PrimaryCheckout::forWorktree($root);

            if ($primary !== null) {
                $path = $primary.'/.env';

                if (is_link($path)) {
                    throw new FileException('Refusing to copy a symbolic link for the primary checkout environment.');
                }

                if (is_file($path)) {
                    return $path;
                }
            }
        }

        return $root.'/.env.example';
    }
}
