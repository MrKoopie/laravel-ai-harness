# Feature Set

Laravel AI Harness starts with the features extracted from the existing Laravel app setup:

- Agent instructions for Codex and Claude.
- Shared MCP configuration for Laravel Boost.
- Project-local helper scripts so agents can call Artisan through Herd, Sail, or local PHP.
- Generated Codex and Claude hooks for worktree/session setup.
- Codex worktree provisioning that configures an isolated `APP_URL`, app database, companion testing database, app key, app/testing migrations, PHPUnit test database wiring, cleanup restoration, and doctor check.
- Optional Herd link/unlink automation for temporary Codex worktrees.
- Generated local skills for harness-aware agent behavior.
- A managed `.gitignore` block that keeps generated harness files trackable in Laravel projects that ignore `.codex` or similar local directories.
- Optional Polyscope workspace metadata.
- Optional Docker testing database bootstrap script.
- Composer hooks that refresh managed files after install/update.
- Quality tooling for the package itself: Pest, Orchestra Testbench, Larastan/PHPStan, Pint, and GitHub Actions.

The package is meant to be repeatable rather than magical. The first `ai-harness:install` opts a project into the harness and Composer hooks. Later `composer install`, `composer update`, or `ai-harness:update` refresh only package-managed content.

## Initial Scope

The first public package should focus on:

- installing predictable harness files;
- preserving user-owned content outside managed blocks;
- exposing clear driver contracts;
- supporting Codex and Claude out of the box;
- making generated harness files committable so future worktrees receive the hooks from Git;
- making Herd workspace automation, Docker, and Polyscope opt-in;
- proving behavior through package tests and a real Laravel validation app.

## Later Scope

Likely follow-up features:

- richer conflict detection for modified managed files;
- `--dry-run` and `--diff`;
- separate driver packages;
- project package detection for Pest, Pint, Larastan, Boost, Tailwind, Sail, Herd, Telescope, and Pail;
- upgrade notes per package version.
