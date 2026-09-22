<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Environment;

enum ExecutionEnvironment: string
{
    case Local = 'local';
    case ClaudeCloud = 'claude-cloud';
    case CodexCloud = 'codex-cloud';

    /** Detect cloud execution only from an explicit, supported signal. */
    public static function current(): self
    {
        $explicit = getenv('AI_HARNESS_ENV');

        if (is_string($explicit) && $explicit !== '') {
            return self::tryFrom($explicit)
                ?? throw new EnvironmentException('AI_HARNESS_ENV must be local, claude-cloud, or codex-cloud.');
        }

        return getenv('CLAUDE_CODE_REMOTE') === 'true' ? self::ClaudeCloud : self::Local;
    }

    /** Determine whether native cloud preparation should be used. */
    public function isCloud(): bool
    {
        return $this !== self::Local;
    }

    /** Remove inherited application connection settings so cloud .env files take effect.
     * @return array<string, string|false>
     */
    public static function processEnvironment(): array
    {
        if (! self::current()->isCloud()) {
            return [];
        }

        $environment = ['COMPOSER_ALLOW_SUPERUSER' => '1', 'COMPOSER_NO_DEV' => '0'];

        foreach (array_keys(getenv()) as $key) {
            if (str_starts_with($key, 'DB_') || str_starts_with($key, 'REDIS_')
                || in_array($key, ['MYSQL_ATTR_SSL_CA', 'DATABASE_URL', 'APP_ENV', 'APP_KEY', 'APP_URL', 'APP_CONFIG_CACHE', 'CACHE_STORE', 'CACHE_DRIVER', 'QUEUE_CONNECTION', 'SESSION_DRIVER', 'MAIL_MAILER'], true)) {
                $environment[$key] = false;
            }
        }

        return $environment;
    }
}
