<?php

use MrKoopie\LaravelAiHarness\Generation\ManagedBlockWriter;

test('service provider uses the configured managed block marker', function (): void {
    config()->set('ai-harness.package.marker', 'custom-harness');
    app()->forgetInstance(ManagedBlockWriter::class);

    $path = temp_file('managed-block');

    app(ManagedBlockWriter::class)->write($path, 'Generated guidance.');

    expect(file_get_contents($path))
        ->toContain('<!-- custom-harness:start -->')
        ->toContain('<!-- custom-harness:end -->');
});
