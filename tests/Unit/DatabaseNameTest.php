<?php

declare(strict_types=1);

use MrKoopie\LaravelAiHarness\Environment\DatabaseName;

test('checkout testing database names leave room for Laravel parallel worker tokens', function (): void {
    $root = '/tmp/'.str_repeat('long-branch-name-', 5);

    expect(strlen(DatabaseName::testingForPath($root).'_test_999999'))->toBeLessThanOrEqual(64);
});
