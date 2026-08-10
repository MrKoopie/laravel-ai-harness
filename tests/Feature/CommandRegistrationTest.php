<?php

test('package commands are registered', function (): void {
    pending_artisan('list')
        ->expectsOutputToContain('ai-harness:install')
        ->expectsOutputToContain('ai-harness:update')
        ->expectsOutputToContain('ai-harness:doctor')
        ->expectsOutputToContain('ai-harness:prune-herd')
        ->assertSuccessful();
});
