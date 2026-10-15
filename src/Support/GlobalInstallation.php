<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Support;

use MrKoopie\LaravelAiHarness\Environment\ExecutionEnvironment;

final class GlobalInstallation
{
    public const CONFIG_FILE = '.ai-harness.config.global';

    public const CODEX_ENVIRONMENT = '.codex/environments/ai-harness-global.toml';

    /** Retain the installation mode in this checkout and its linked local worktrees. */
    public static function active(string $root): bool
    {
        if (is_file($root.'/'.self::CONFIG_FILE)) {
            return true;
        }

        $primary = ExecutionEnvironment::current()->isCloud() ? null : PrimaryCheckout::forWorktree($root);

        return $primary !== null && is_file($primary.'/'.self::CONFIG_FILE);
    }
}
