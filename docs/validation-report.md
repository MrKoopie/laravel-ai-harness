# Laravel AI Harness Validation Report

Date: 2026-06-30

## Scope

This report covers the README/configuration hardening, Herd workspace automation, Laravel Sail compatibility coverage, package tests, GitHub Actions workflow, and a fresh Laravel application validation run.

## Team-Parity Checklist

- Laravel package configuration follows Laravel package conventions: package config can be published with `php artisan vendor:publish --tag=ai-harness-config`, and runtime defaults remain in `config/ai-harness.php`.
- Generated human-editable files use managed blocks, while generated scripts/config files are package-owned.
- Generated `.gitignore` rules unignore only package-managed AI Harness files so the required `.codex`, `.claude`, `.ai`, `.agents`, and `.dev` artifacts can be committed before worktrees are created.
- Optional environment-specific behavior is opt-in: Herd workspace linking, Docker test database bootstrap, and Polyscope metadata are disabled by default.
- Composer auto-update hooks are guarded so production `composer install --no-dev` can skip the package when installed as a dev dependency.
- CI now tests the supported Laravel lines: Laravel 11, 12, and 13 via matching Illuminate and Testbench constraints, with PHP 8.5 included on the latest Laravel line.
- Static quality checks are explicit: Composer validation, Pint, Pest, and PHPStan/Larastan.
- Sail compatibility is covered by an automated runtime-helper test that proves Sail is preferred when `vendor/bin/sail` and a container runtime are available.
- Herd automation is covered by tests for opt-in link/unlink behavior, default disabled behavior, collision-resistant site names, DNS-label-length-capped site names, idempotent missing-link cleanup, unexpected unlink failure surfacing, per-worktree SQLite setup/teardown, Sail-backed MySQL provisioning, and Codex cleanup using the worktree path.

## Documentation Inputs

- Laravel package docs via Context7: config publishing/tagging and package configuration conventions.
- Laravel Sail docs via Context7: `php artisan sail:install` and `sail test` command usage.
- Local Herd CLI help: verified `herd link`, `herd unlink`, `herd php`, and `herd sites` availability on this machine.

## Package Validation

Commands run in the package checkout:

| Command | Result |
| --- | --- |
| `composer test` | Passed: 35 tests, 156 assertions |
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
- PHP: `8.5.5`

Install flow:

1. Added a Composer path repository pointing at this checkout with `symlink=false`.
2. Installed this PR branch of `mrkoopie/laravel-ai-harness` as a dev dependency from the path repository.
3. Ran `php artisan ai-harness:install --with=herd`.
4. Ran `php artisan ai-harness:doctor --with=herd`.

Results:

- Package was discovered by Laravel package discovery.
- Harness install succeeded and patched Composer `post-install-cmd` and `post-update-cmd`.
- Composer hooks preserved `ai-harness:update --ansi --with=herd`.
- Doctor output reported `Agents: claude, codex, cursor`, `Runtimes: bare, herd, sail`, and `AI harness looks healthy.`
- Expected generated files existed under `AGENTS.md`, `CLAUDE.md`, `.ai`, `.dev`, `.codex`, `.claude`, and `.agents`.
- The generated `.gitignore` included narrowed AI Harness unignore rules for package-managed `.codex`, `.claude`, `.ai`, `.agents`, and `.dev` artifacts.
- Fresh Laravel app tests passed with `php artisan test`: 2 tests, 2 assertions.

## Herd Workspace Setup And Teardown

Command run for setup:

```bash
CODEX_WORKTREE_PATH=<temporary-validation-app> WORKTREE_PROFILE=codex bash .codex/scripts/local-environment.sh setup
```

Observed setup result:

- Herd created site link `<generated-herd-site>`, including the full-path checksum suffix.
- `.env` contained `APP_URL=http://<generated-herd-site>.test`.
- `.env` contained `DB_DATABASE=database/<generated-worktree-database>.sqlite`.
- Direct filesystem check confirmed the generated SQLite database existed before teardown.
- The setup hook ran Laravel migrations against the generated worktree database.
- The setup hook completed `ai-harness:doctor` successfully.

Command run for cleanup:

```bash
CODEX_WORKTREE_PATH=<temporary-validation-app> WORKTREE_PROFILE=codex bash .codex/scripts/local-environment.sh cleanup
```

Observed cleanup result:

- Herd removed the `<generated-herd-site>` symbolic link.
- Direct filesystem check confirmed the generated SQLite database file was removed.
- Direct filesystem check confirmed the generated Herd site link no longer exists under Herd's local `Sites` directory.

Local Herd note:

- Some Herd introspection commands emitted repeated PHP warnings/deprecations on this machine under PHP 8.5, so teardown verification used direct filesystem checks.

## Existing test-ai Worktree Validation

The existing `test-ai` project had an unrelated Composer script issue during package installation: its `post-update-cmd` referenced `php artisan boost:update`, but the validation checkout did not expose any `boost` artisan commands. To keep that app-specific issue from masking the harness behavior, the package was installed in a throwaway source branch with Composer scripts bypassed, then `package:discover` and `ai-harness:install --with=herd` were run directly.

Validation flow:

1. Installed this PR branch of `mrkoopie/laravel-ai-harness` into a temporary `test-ai` source branch from the committed app state.
2. Ran `php artisan ai-harness:install --with=herd`.
3. Committed the generated harness files in the temporary source branch.
4. Created a fresh Codex-style worktree from that commit.
5. Ran the generated setup hook with `WORKTREE_PROFILE=codex`.
6. Ran the generated cleanup hook with `WORKTREE_PROFILE=codex`.

Observed setup result:

- Herd created a deterministic per-worktree site link `<test-ai-herd-site>`.
- `.env` contained `APP_URL=http://<test-ai-herd-site>.test`.
- `.env` contained `DB_CONNECTION=mariadb`.
- `.env` contained `DB_DATABASE=<test-ai-worktree-database>`, using the generated database name with a full-path checksum suffix.
- `.env` contained a generated `APP_KEY`.
- An independent PDO query confirmed `<test-ai-worktree-database>` existed after setup.
- Laravel migrations were marked as ran in the generated database.
- `php artisan test` passed in the generated worktree: 2 tests, 2 assertions.
- `php artisan ai-harness:doctor` completed successfully from the setup hook.

Observed cleanup result:

- The generated cleanup hook removed the Herd site link.
- An independent PDO query confirmed `<test-ai-worktree-database>` no longer existed after cleanup.
- A direct filesystem check confirmed the generated Herd site link no longer existed under Herd's local `Sites` directory.

## Outcome

The package is on-par with the expected Laravel package workflow for this scope: documented defaults, explicit configuration locations, generated files that can be committed before worktree creation, opt-in local workspace automation, Sail-aware runtime selection, per-worktree URL/database provisioning, automated package coverage, CI coverage across supported Laravel lines, and a successful fresh Laravel application validation including Herd setup and teardown.
