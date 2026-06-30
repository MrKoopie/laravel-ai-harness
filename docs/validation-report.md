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
- Herd automation is covered by tests for opt-in link/unlink behavior, default disabled behavior, collision-resistant site names, DNS-label-length-capped site names, idempotent missing-link cleanup, unexpected unlink failure surfacing, per-worktree SQLite setup/teardown, Sail-backed MySQL provisioning, and cleanup using the generated worktree path.
- Claude worktree automation is covered by tests proving `EnterWorktree`, `ExitWorktree`, and guarded `SessionStart` wrappers delegate to the generated local-environment script and do not run setup against the main checkout.

## Documentation Inputs

- Laravel package docs via Context7: config publishing/tagging and package configuration conventions.
- Laravel Sail docs via Context7: `php artisan sail:install` and `sail test` command usage.
- Local Herd CLI help: verified `herd link`, `herd unlink`, `herd php`, and `herd sites` availability on this machine.

## Package Validation

Commands run in the package checkout:

| Command | Result |
| --- | --- |
| `composer test` | Passed: 42 tests, 216 assertions |
| `composer format:check` | Passed |
| `composer validate --strict` | Passed |
| `composer analyse` | Passed: no PHPStan/Larastan errors |

Note: the final PHPStan/Larastan run was executed outside the filesystem sandbox to avoid local worker socket restrictions; it passed with no errors.

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

The existing `test-ai` project is kept as a committed validation app. It points to the local package path repository and was refreshed from the current package branch through Composer's path repository. Composer records a path package reference rather than the package Git commit, so the validation app commit is the durable provenance.

Validation flow:

1. Reinstalled the local path package in `test-ai`.
2. Composer ran the guarded `ai-harness:update --ansi --with=herd` hook.
3. Committed the refreshed generated harness files in `test-ai` at `8722e09`.
4. Created a fresh Codex App worktree from that commit at `<codex-test-ai-worktree>`.
5. Confirmed the programmatic Codex App worktree creation did not select/run the local environment: `.env` was absent, `phpunit.xml` still used sqlite `:memory:`, and no local-environment state existed.
6. Ran the generated Codex local environment setup command from the worktree with `WORKTREE_PROFILE=codex`.
7. Ran the generated cleanup hook with `WORKTREE_PROFILE=codex`.

Observed setup result:

- Herd created deterministic per-worktree site link `test-ai-415a-3056376673`.
- `.env` contained `APP_URL=http://test-ai-415a-3056376673.test`.
- `.env` contained `DB_CONNECTION=mariadb`.
- `.env` contained `DB_DATABASE=test_ai_3056376673`, using the generated database name with a full-path checksum suffix.
- `.env` contained `AI_HARNESS_TEST_DB_DATABASE=test_ai_testing_3056376673`.
- `.env` contained a generated `APP_KEY`.
- `phpunit.xml` was patched to `DB_CONNECTION=mariadb`, `DB_DATABASE=test_ai_testing_3056376673`, and empty `DB_URL`.
- Independent PDO queries confirmed both generated databases existed after setup.
- Laravel migrations were marked as ran in both generated databases.
- `php artisan test` passed in the generated worktree: 2 tests, 2 assertions.
- `php artisan ai-harness:doctor` completed successfully from the setup hook.

Observed cleanup result:

- The generated cleanup hook removed the Herd site link.
- Independent PDO queries confirmed both generated databases no longer existed after cleanup.
- `phpunit.xml` was restored to sqlite `:memory:`.
- `.codex/local-environment-state` was removed.
- A direct filesystem check confirmed the generated Herd site link no longer existed under Herd's local `Sites` directory.
- Final worktree status was clean.

Codex App note:

- Current OpenAI Codex documentation says local environment setup scripts run automatically when Codex creates a worktree and that choosing a local environment is optional in the new-thread flow. The programmatic `create_thread` tool used for this validation does not expose a local-environment selector, so it created the worktree without running `.codex/environments/environment.toml`. The generated environment itself validated correctly when run from the worktree checkout, which is the documented execution model when the environment is selected.

## Outcome

The package is on-par with the expected Laravel package workflow for this scope: documented defaults, explicit configuration locations, generated files that can be committed before worktree creation, opt-in local workspace automation, Sail-aware runtime selection, Claude/Codex worktree wrapper coverage, per-worktree URL/database provisioning, automated package coverage, CI coverage across supported Laravel lines including PHP 8.5, and successful Laravel application validation including Herd setup and teardown.
