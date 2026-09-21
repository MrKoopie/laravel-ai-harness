<?php

declare(strict_types=1);

use MrKoopie\LaravelAiHarness\Support\ProjectPath;

test('project path preserves the filesystem root', function (): void {
    expect(ProjectPath::resolve('/'))->toBe('/');
});
