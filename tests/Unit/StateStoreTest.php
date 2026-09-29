<?php

declare(strict_types=1);

use MrKoopie\LaravelAiHarness\Environment\SiteTool;
use MrKoopie\LaravelAiHarness\Environment\StateStore;
use MrKoopie\LaravelAiHarness\Files\SafeWriter;

test('an empty state object remains readable after clearing TLS state', function (): void {
    $root = temp_directory('harness-empty-state');
    file_put_contents($root.'/.ai-harness.state.json', '{"herd_secured":true}');

    $store = new StateStore(new SafeWriter);
    $store->clearSiteSecured($root, SiteTool::Herd);

    expect(file_get_contents($root.'/.ai-harness.state.json'))->toBe("{}\n")
        ->and($store->siteSecured($root, SiteTool::Herd))->toBeFalse()
        ->and($store->site($root, SiteTool::Herd))->toBeNull();
});

test('MySQL ownership state survives Herd cleanup and is removable independently', function (): void {
    $root = temp_directory('harness-mysql-state');
    $store = new StateStore(new SafeWriter);

    $store->recordSite($root, SiteTool::Herd, 'example-site');
    $store->recordMySqlDatabases($root);
    $store->clearSite($root, SiteTool::Herd);

    expect($store->ownsMySqlDatabases($root))->toBeTrue()
        ->and(json_decode((string) file_get_contents($root.'/.ai-harness.state.json'), true, flags: JSON_THROW_ON_ERROR))
        ->toBe(['mysql_databases' => true]);

    $store->clearMySqlDatabases($root);

    expect($root.'/.ai-harness.state.json')->not->toBeFile();
});
