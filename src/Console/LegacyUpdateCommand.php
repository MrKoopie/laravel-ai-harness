<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Console;

use Illuminate\Console\Command;
use MrKoopie\LaravelAiHarness\Files\LegacyConfigMigrator;
use MrKoopie\LaravelAiHarness\Files\ProjectSynchronizer;
use MrKoopie\LaravelAiHarness\Support\ProjectPath;

class LegacyUpdateCommand extends Command
{
    /** @var string */
    protected $signature = 'ai-harness:update
        {--path= : Project root to update}
        {--with=* : Legacy v0.1 feature selection}';

    /** @var string */
    protected $description = 'Compatibility bridge for Laravel AI Harness v0.1 Composer hooks';

    /** Run the old Artisan entrypoint through the new file-only synchronizer. */
    public function handle(LegacyConfigMigrator $migrator, ProjectSynchronizer $synchronizer): int
    {
        $root = $this->projectRoot();
        $with = $this->option('with');
        $migration = $migrator->migrate(
            $root,
            array_replace($this->legacyFeatureDefaults(), $this->legacyConfig('ai-harness.features')),
            array_replace($this->legacyProjectDefaults(), $this->legacyConfig('ai-harness.project')),
            is_array($with) ? array_values(array_filter($with, is_string(...))) : [],
        );

        $this->components->warn('Using the v0.1 compatibility command; future updates use ./.ai-harness update.');

        if ($migration->created) {
            $this->line('created .ai-harness.config');
        }

        foreach ($migration->warnings as $warning) {
            $this->components->warn($warning);
        }

        $result = $synchronizer->sync($root);

        foreach ($result->written as $file) {
            $this->line("updated {$file}");
        }

        $this->components->info('AI Harness project files refreshed.');

        return self::SUCCESS;
    }

    /** Resolve the explicit path or Laravel's application base path. */
    private function projectRoot(): string
    {
        $path = $this->option('path');

        if (is_string($path) && $path !== '') {
            return ProjectPath::resolve($path);
        }

        return ProjectPath::resolve($this->laravel->basePath());
    }

    /** @return array<string, mixed> */
    private function legacyFeatureDefaults(): array
    {
        return [
            'codex' => $this->environment('AI_HARNESS_CODEX', true),
            'claude' => $this->environment('AI_HARNESS_CLAUDE', true),
            'skills' => $this->environment('AI_HARNESS_SKILLS', true),
            'herd' => $this->environment('AI_HARNESS_HERD', false),
            'docker' => $this->environment('AI_HARNESS_DOCKER', false),
            'polyscope' => $this->environment('AI_HARNESS_POLYSCOPE', false),
        ];
    }

    /** @return array<string, mixed> */
    private function legacyProjectDefaults(): array
    {
        return [
            'php_version' => $this->environment('AI_HARNESS_PHP_VERSION'),
        ];
    }

    /** Read a legacy environment value populated by Laravel Dotenv. */
    private function environment(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $_ENV)) {
            return $_ENV[$key];
        }

        if (array_key_exists($key, $_SERVER)) {
            return $_SERVER[$key];
        }

        $value = getenv($key);

        return $value === false ? $default : $value;
    }

    /** @return array<string, mixed> */
    private function legacyConfig(string $key): array
    {
        $config = $this->laravel->make('config');
        $value = is_object($config) && method_exists($config, 'get') ? $config->get($key, []) : [];

        return is_array($value) ? $value : [];
    }
}
