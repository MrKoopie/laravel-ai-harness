<?php

return [
    'package' => [
        'name' => 'mrkoopie/laravel-ai-harness',
        'marker' => 'ai-harness',
    ],

    'agents' => [
        'claude',
        'codex',
        'cursor',
    ],

    'runtimes' => [
        'bare',
        'herd',
        'sail',
    ],

    'workspaces' => [
        'claude',
        'codex',
        'polyscope',
    ],

    'features' => [
        'codex' => env('AI_HARNESS_CODEX', true),
        'claude' => env('AI_HARNESS_CLAUDE', true),
        'skills' => env('AI_HARNESS_SKILLS', true),
        'herd' => env('AI_HARNESS_HERD', false),
        'docker' => env('AI_HARNESS_DOCKER', false),
        'polyscope' => env('AI_HARNESS_POLYSCOPE', false),
    ],

    'composer' => [
        'auto_update' => true,
        'script' => '@php artisan ai-harness:update --ansi',
    ],

    'project' => [
        'name' => env('AI_HARNESS_PROJECT_NAME'),
        'slug' => env('AI_HARNESS_PROJECT_SLUG'),
        'database_name' => env('AI_HARNESS_DATABASE_NAME'),
        'php_version' => env('AI_HARNESS_PHP_VERSION'),
        'worktree_base_ref' => env('AI_HARNESS_WORKTREE_BASE_REF'),
        'queue_connection' => 'database',
    ],
];
