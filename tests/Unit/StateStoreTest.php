<?php

declare(strict_types=1);

use MrKoopie\LaravelAiHarness\Environment\StateStore;
use MrKoopie\LaravelAiHarness\Files\SafeWriter;

test('an empty state object remains readable after clearing TLS state', function (): void {
    $root = temp_directory('harness-empty-state');
    file_put_contents($root.'/.ai-harness.state.json', '{"herd_secured":true}');

    $store = new StateStore(new SafeWriter);
    $store->clearHerdSecured($root);

    expect(file_get_contents($root.'/.ai-harness.state.json'))->toBe("{}\n")
        ->and($store->herdSecured($root))->toBeFalse()
        ->and($store->herdSite($root))->toBeNull();
});
