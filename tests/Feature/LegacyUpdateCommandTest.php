<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Contracts\Container\Container as ContainerContract;
use MrKoopie\LaravelAiHarness\Console\LegacyUpdateCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

test('legacy artisan update command migrates options and refreshes project files', function (): void {
    $root = temp_directory('harness-legacy-command');
    file_put_contents($root.'/artisan', "<?php\n");
    file_put_contents($root.'/composer.json', json_encode([
        'name' => 'example/application',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");

    $container = new class extends Container
    {
        public function runningUnitTests(): bool
        {
            return true;
        }
    };
    $container->instance(ContainerContract::class, $container);
    $container->instance('config', new class
    {
        public function get(string $key, mixed $default = null): mixed
        {
            return match ($key) {
                'ai-harness.features' => ['codex' => true, 'claude' => true],
                'ai-harness.project' => ['php_version' => '8.4'],
                default => $default,
            };
        }
    });

    $command = new LegacyUpdateCommand;
    $command->setLaravel($container);
    $application = new Application;
    $application->addCommand($command);
    $tester = new CommandTester($application->find('ai-harness:update'));

    $status = $tester->execute([
        '--path' => $root,
        '--with' => ['herd', 'docker'],
    ]);

    expect($status)->toBe(0)
        ->and($tester->getDisplay())->toContain('compatibility command')
        ->and((string) file_get_contents($root.'/.ai-harness.config'))->toContain('runtime=herd')
        ->toContain('services=sail')
        ->and($root.'/.ai-harness')->toBeFile()
        ->and((string) file_get_contents($root.'/composer.json'))->toContain('laravel-ai-harness:update');
});

test('package metadata retains the legacy provider class for Laravel discovery', function (): void {
    $composer = json_decode((string) file_get_contents(package_root().'/composer.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($composer['extra']['laravel']['providers'])->toContain(
        'MrKoopie\\LaravelAiHarness\\LaravelAiHarnessServiceProvider',
    );
});

test('upgrade documentation routes v0.1 no-scripts recovery through the migration bridge', function (): void {
    $readme = (string) file_get_contents(package_root().'/README.md');

    expect($readme)->toContain('composer update mrkoopie/laravel-ai-harness --no-scripts')
        ->toContain('php artisan ai-harness:update --ansi');
});

test('legacy artisan update migrates environment-only v0.1 settings', function (): void {
    $root = temp_directory('harness-legacy-environment');
    file_put_contents($root.'/artisan', "<?php\n");
    $_ENV['AI_HARNESS_CODEX'] = 'false';
    $_ENV['AI_HARNESS_CLAUDE'] = 'true';
    $_ENV['AI_HARNESS_HERD'] = 'true';
    $_ENV['AI_HARNESS_DOCKER'] = 'true';
    $_ENV['AI_HARNESS_PHP_VERSION'] = '8.4';

    try {
        $container = legacy_command_container($root);
        $tester = legacy_command_tester($container);
        $status = $tester->execute(['--path' => $root]);
    } finally {
        foreach (['AI_HARNESS_CODEX', 'AI_HARNESS_CLAUDE', 'AI_HARNESS_HERD', 'AI_HARNESS_DOCKER', 'AI_HARNESS_PHP_VERSION'] as $key) {
            unset($_ENV[$key]);
        }
    }

    $config = (string) file_get_contents($root.'/.ai-harness.config');

    expect($status)->toBe(0)
        ->and($config)->toContain('runtime=herd')
        ->toContain('services=sail')
        ->toContain('agents=claude')
        ->toContain('herd_php=8.4');
});

test('legacy artisan update defaults to the Laravel application base path', function (): void {
    $root = temp_directory('harness-legacy-base-path');
    file_put_contents($root.'/artisan', "<?php\n");
    $container = legacy_command_container($root);
    $tester = legacy_command_tester($container);

    $status = $tester->execute([]);

    expect($status)->toBe(0)
        ->and($root.'/.ai-harness.config')->toBeFile()
        ->and($root.'/.ai-harness')->toBeFile();
});

function legacy_command_container(string $root): Container
{
    $container = new class($root) extends Container
    {
        public function __construct(private readonly string $root) {}

        public function runningUnitTests(): bool
        {
            return true;
        }

        public function basePath(string $path = ''): string
        {
            return $this->root.($path === '' ? '' : DIRECTORY_SEPARATOR.$path);
        }
    };
    $container->instance(ContainerContract::class, $container);
    $container->instance('config', new class
    {
        public function get(string $key, mixed $default = null): mixed
        {
            return $default;
        }
    });

    return $container;
}

function legacy_command_tester(Container $container): CommandTester
{
    $command = new LegacyUpdateCommand;
    $command->setLaravel($container);
    $application = new Application;
    $application->addCommand($command);

    return new CommandTester($application->find('ai-harness:update'));
}
