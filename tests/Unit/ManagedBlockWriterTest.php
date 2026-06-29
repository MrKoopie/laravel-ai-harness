<?php

use Yourwebhoster\LaravelAiHarness\Generation\ManagedBlockWriter;

test('it appends a managed block when no block exists', function (): void {
    $path = temp_file('managed-block');

    file_put_contents($path, "# Project Rules\n\nKeep this custom rule.\n");

    $writer = new ManagedBlockWriter('ai-harness');
    $writer->write($path, 'Generated guidance.');

    expect(file_get_contents($path))
        ->toContain('Keep this custom rule.')
        ->toContain('<!-- ai-harness:start -->')
        ->toContain('Generated guidance.')
        ->toContain('<!-- ai-harness:end -->');
});

test('it replaces only the managed block on update', function (): void {
    $path = temp_file('managed-block');

    file_put_contents($path, implode("\n", [
        '# Project Rules',
        '',
        'Custom rule before.',
        '',
        '<!-- ai-harness:start -->',
        'Old generated guidance.',
        '<!-- ai-harness:end -->',
        '',
        'Custom rule after.',
        '',
    ]));

    $writer = new ManagedBlockWriter('ai-harness');
    $writer->write($path, 'Fresh generated guidance.');

    expect(file_get_contents($path))
        ->toContain('Custom rule before.')
        ->toContain('Fresh generated guidance.')
        ->toContain('Custom rule after.')
        ->not()->toContain('Old generated guidance.');
});

test('it replaces managed blocks without treating content as regex backreferences', function (): void {
    $path = temp_file('managed-block');

    file_put_contents($path, implode("\n", [
        '<!-- ai-harness:start -->',
        'Old generated guidance.',
        '<!-- ai-harness:end -->',
        '',
    ]));

    $writer = new ManagedBlockWriter('ai-harness');
    $writer->write($path, 'Keep $1 and \1 literal.');

    expect(file_get_contents($path))
        ->toContain('Keep $1 and \1 literal.')
        ->not()->toContain('Old generated guidance.');
});
