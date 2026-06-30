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
- Codex hook fallback is covered by a test proving the generated `SessionStart` hook provisions only Codex-managed worktrees under `$CODEX_HOME/worktrees/*` and does not run setup against a normal source checkout.
- Claude worktree automation is covered by tests proving `EnterWorktree`, `ExitWorktree`, and guarded `SessionStart` wrappers delegate to the generated local-environment script and do not run setup against the main checkout.

## Documentation Inputs

- Laravel package docs via Context7: config publishing/tagging and package configuration conventions.
- Laravel Sail docs via Context7: `php artisan sail:install` and `sail test` command usage.
- Local Herd CLI help: verified `herd link`, `herd unlink`, `herd php`, and `herd sites` availability on this machine.

## Package Validation

Commands run in the package checkout before the final report-only commits:

Code-changing validation commit: `1912534`. Later commits updated this report only.

| Command | Result |
| --- | --- |
| `composer test` | Passed: 43 tests, 220 assertions |
| `composer format:check` | Passed |
| `composer validate --strict` | Passed |
| `composer analyse` | Passed: no PHPStan/Larastan errors |

Note: the final PHPStan/Larastan run was executed outside the filesystem sandbox to avoid local worker socket restrictions; it passed with no errors.

## Pull Request Validation

PR: `https://github.com/MrKoopie/laravel-ai-harness/pull/2`

Observed GitHub checks after the final push:

- CodeRabbit: passed.
- Pest PHP 8.2 / Laravel 11: passed.
- Pest PHP 8.3 / Laravel 12: passed.
- Pest PHP 8.4 / Laravel 13: passed.
- Pest PHP 8.5 / Laravel 13: passed.
- Static Analysis and Style: passed.

Local CodeRabbit CLI note:

- The local CLI is installed at version `0.6.4`.
- `coderabbit auth status --agent` reported `not_authenticated`.
- A local `coderabbit review --agent -t committed --base main` run was requested but blocked by the approval reviewer because it would send the branch diff to CodeRabbit's external API and repository public status was not verified.

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

## Codex App Worktree Validation

A fresh Laravel validation app was created under a temporary local test project, installed from this branch through a Composer path repository, generated with `--with=herd`, and committed before Codex App worktree creation. Composer records a path package reference rather than the package Git commit, so the validation app commit is the durable provenance.

Validation flow:

1. Created a fresh Laravel app and installed this branch of `mrkoopie/laravel-ai-harness` as a dev dependency.
2. Ran `ai-harness:install --with=herd` and `ai-harness:update --with=herd`.
3. Committed the generated harness files in the validation app.
4. Created one Codex App worktree through the programmatic `create_thread` tool and confirmed it did not run setup because that tool does not expose a local-environment selector.
5. Created a second Codex App worktree through the App new-thread flow with the generated local environment selected.
6. Verified the selected local environment ran `.codex/environments/environment.toml`, which delegated to `.codex/scripts/local-environment.sh setup`.
7. Archived the Codex App thread and verified cleanup ran before the managed worktree was removed.

Observed selected-local-environment setup result:

- Herd created deterministic per-worktree site link `ai-harness-e2e-20260630-02-d1ba-3389622215`.
- `.env` contained `APP_URL=http://ai-harness-e2e-20260630-02-d1ba-3389622215.test`.
- `.env` contained `DB_CONNECTION=mariadb`.
- `.env` contained `DB_DATABASE=ai_harness_e2e_20260630_02_3389622215`, using the generated database name with a full-path checksum suffix.
- `.env` contained `AI_HARNESS_TEST_DB_DATABASE=ai_harness_e2e_20260630_02_testing_3389622215`.
- `.env` contained a generated `APP_KEY`.
- `phpunit.xml` was patched to `DB_CONNECTION=mariadb` and `DB_DATABASE=ai_harness_e2e_20260630_02_testing_3389622215`.
- Independent MySQL queries confirmed both generated databases existed after setup.
- Independent MySQL queries confirmed 3 migration rows in each generated database.
- `php artisan test --without-tty --do-not-cache-result` passed in the generated worktree: 2 tests, 2 assertions.
- `php artisan ai-harness:doctor --with=herd --ansi` completed successfully after setup.

Observed Codex App archive cleanup result:

- Archiving the Codex App thread removed the managed worktree directory.
- Herd no longer listed `ai-harness-e2e-20260630-02-d1ba-3389622215`.
- Independent MySQL queries confirmed both generated databases no longer existed after cleanup.
- The source validation app remained clean after teardown.

Codex App note:

- The generated local environment path validates end-to-end when it is selected in the Codex App new-thread flow.
- The programmatic `create_thread` tool used during validation does not expose a local-environment selector, so it creates a worktree without running `.codex/environments/environment.toml`.
- The generated `SessionStart` hook is a guarded fallback for Codex-managed worktrees, but Codex project command hooks still require user review/trust before Codex runs them automatically.

## Outcome

The package is on-par with the expected Laravel package workflow for this scope: documented defaults, explicit configuration locations, generated files that can be committed before worktree creation, opt-in local workspace automation, Sail-aware runtime selection, Claude/Codex worktree coverage, per-worktree URL/database provisioning, automated package coverage, CI coverage across supported Laravel lines including PHP 8.5, and successful Laravel application validation including Codex App local-environment setup, Herd provisioning, database isolation, and teardown.

Remaining Codex App constraint: tool-created Codex worktrees do not run setup until the local environment is selected or the generated project hook command is reviewed/trusted. The package documents that constraint and provides both generated paths, but it cannot bypass Codex's local-environment selection or project-hook trust model.
