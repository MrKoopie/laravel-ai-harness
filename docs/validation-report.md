# Laravel AI Harness Validation Report

Date: 2026-06-30

## Scope

This report covers the README/configuration hardening, Herd workspace automation, Laravel Sail compatibility coverage, package tests, GitHub Actions workflow, and a fresh Laravel application validation run.

## Team-Parity Checklist

- Laravel package configuration follows Laravel package conventions: package config can be published with `php artisan vendor:publish --tag=ai-harness-config`, and runtime defaults remain in `config/ai-harness.php`.
- Generated human-editable files use managed blocks, while generated scripts/config files are package-owned.
- Optional environment-specific behavior is opt-in: Herd workspace linking, Docker test database bootstrap, and Polyscope metadata are disabled by default.
- Composer auto-update hooks are guarded so production `composer install --no-dev` can skip the package when installed as a dev dependency.
- CI now tests the supported Laravel lines: Laravel 11, 12, and 13 via matching Illuminate and Testbench constraints.
- Static quality checks are explicit: Composer validation, Pint, Pest, and PHPStan/Larastan.
- Sail compatibility is covered by an automated runtime-helper test that proves Sail is preferred when `vendor/bin/sail` and a container runtime are available.
- Herd automation is covered by tests for opt-in link/unlink behavior, default disabled behavior, collision-resistant site names, idempotent missing-link cleanup, unexpected unlink failure surfacing, and Codex cleanup using the worktree path.

## Documentation Inputs

- Laravel package docs via Context7: config publishing/tagging and package configuration conventions.
- Laravel Sail docs via Context7: `php artisan sail:install` and `sail test` command usage.
- Local Herd CLI help: verified `herd link`, `herd unlink`, `herd php`, and `herd sites` availability on this machine.

## Package Validation

Commands run in the package checkout:

| Command | Result |
| --- | --- |
| `composer test` | Passed: 30 tests, 122 assertions |
| `composer format:check` | Passed |
| `composer validate --strict` | Passed |
| `composer analyse` | Passed: no PHPStan/Larastan errors |

Note: PHPStan needed to run outside the filesystem sandbox because Larastan/PHPStan opened a local TCP worker socket and the sandbox denied it with `EPERM`.

## Fresh Laravel Project Validation

Validation app path:

```text
<temporary-validation-app>
```

Created with:

```bash
composer create-project laravel/laravel <temporary-validation-app> --no-interaction --prefer-dist
```

Observed versions:

- Laravel skeleton: `laravel/laravel` `v13.8.0`
- Laravel Framework: `v13.17.0`
- PHP: `8.5.7` from Laravel Herd

Install flow:

1. Added a Composer path repository pointing at this checkout with `symlink=false`.
2. Installed `mrkoopie/laravel-ai-harness` as a dev dependency.
3. Ran `php artisan ai-harness:install --with=herd`.
4. Ran `php artisan ai-harness:doctor --with=herd`.

Results:

- Package was discovered by Laravel package discovery.
- Harness install succeeded and patched Composer `post-install-cmd` and `post-update-cmd`.
- Composer hooks preserved `ai-harness:update --ansi --with=herd`.
- Doctor output reported `Agents: claude, codex, cursor`, `Runtimes: bare, herd, sail`, and `AI harness looks healthy.`
- Expected generated files existed under `AGENTS.md`, `CLAUDE.md`, `.ai`, `.dev`, `.codex`, `.claude`, and `.agents`.
- Fresh Laravel app tests passed with `php artisan test`: 2 tests, 2 assertions.

## Herd Workspace Setup And Teardown

Command run for setup:

```bash
CODEX_WORKTREE_PATH=<temporary-validation-app> WORKTREE_PROFILE=codex bash .codex/scripts/local-environment.sh setup
```

Observed setup result:

- Herd created site link `<generated-herd-site>`, including the full-path checksum suffix.
- Herd updated `.env` `APP_URL` to `http://<generated-herd-site>.test`.
- The setup hook completed `ai-harness:doctor` successfully.

Command run for cleanup:

```bash
CODEX_WORKTREE_PATH=<temporary-validation-app> WORKTREE_PROFILE=codex bash .codex/scripts/local-environment.sh cleanup
```

Observed cleanup result:

- Herd removed the `<generated-herd-site>` symbolic link.
- Direct filesystem check confirmed the generated Herd site link no longer exists under Herd's local `Sites` directory.

Local Herd note:

- `herd site-information <temporary-validation-app>` emitted repeated PHP warnings/deprecations on this machine under PHP 8.5, so teardown verification used the deterministic symlink path instead.

## Outcome

The package is on-par with the expected Laravel package workflow for this scope: documented defaults, explicit configuration locations, opt-in local workspace automation, Sail-aware runtime selection, automated package coverage, CI coverage across supported Laravel lines, and a successful fresh Laravel application validation including Herd setup and teardown.
