# Development

This page is for contributors to the package itself.

## Requirements

- PHP 8.2 or newer.
- Composer 2.
- [ShellCheck](https://www.shellcheck.net) for the shell script checks.

## Set up

```bash
git clone https://github.com/MrKoopie/laravel-ai-harness.git
cd laravel-ai-harness
composer install
```

## Checks

Run all checks before you open a pull request. CI runs the same checks.

| Command | Check |
| --- | --- |
| `composer test` | Pest test suite. |
| `composer format:check` | Code style with Laravel Pint. Use `composer format` to fix. |
| `composer analyse` | Static analysis with PHPStan. |
| `composer shellcheck` | ShellCheck on the bundled shell scripts. |
| `composer validate --strict` | Composer metadata. |

CI runs the tests on PHP 8.2, 8.3, 8.4, and 8.5.

## Test coverage

The test suite covers:

- Configuration layering.
- Command mapping.
- Resistance to argument injection.
- Safe writes to managed files.
- Bootstrap recovery.
- Herd and Sail composition.
- Cleanup with ownership checks.
- Claude hook payloads.
- Codex and Claude installation.
- The no-Git rule.

## Repository layout

| Path | Contents |
| --- | --- |
| `bin/ai-harness` | CLI entrypoint. |
| `src/Config` | Configuration loading and validation. |
| `src/Console` | CLI commands. |
| `src/Environment` | Runtimes, Sail, Herd, Valet, MySQL, and cloud lifecycle. |
| `src/Files` | Managed project files, Composer scripts, and Claude settings. |
| `src/Health` | `doctor` checks. |
| `resources/project` | Bootstrap, cloud script, default configuration, and `.gitignore` block. |
| `resources/agents` | `AGENTS.md` block and Codex environment. |
| `resources/cloud` | Cloud service and MySQL scripts. |
| `resources/boost` | Laravel Boost guideline. |
| `tests` | Pest unit and feature tests. |

## Rules

- The harness never runs Git commands that change or select branches or worktrees.
- Runtime commands use argument arrays, never shell command strings.
- Configuration files are data. Never source them as shell scripts.
- Cleanup removes only resources that the harness recorded as owned.

## Existing-server database integration tests

The regular suite models server state to inject creation, grant, ownership and cleanup failures. The live integration test is opt-in and requires a **disposable** server with a TCP-accessible root account. It creates test accounts, schemas and the shared harness registry. Never point it at a developer's existing server.

```bash
AI_HARNESS_TEST_DB_HOST=127.0.0.1 \
AI_HARNESS_TEST_DB_PORT=3306 \
AI_HARNESS_TEST_DB_PASSWORD=disposable-root-password \
vendor/bin/pest tests/Feature/ExistingDatabaseTest.php
```

The dedicated CI workflow runs this against `mysql:8.4` and `mariadb:11.4`, using PHP 8.5 with `pdo_mysql`. Without the opt-in endpoint, the live test is skipped. The standard suite continues to cover Sail, SQLite, cloud development-data preservation and runtime selection.

Implementation verification on 2026-10-16 passed against temporary MySQL 8.4.11 and MariaDB 11.8.6 servers with PHP 8.5.10: CLI setup/cleanup, repeated setup with data retention, exact schema grants, parallel schema recreation and cross-checkout cleanup isolation, process credential/host/name overrides and persisted custom database names. Failure injection covers interrupted DDL, failed grants, cleanup retries, missing or mismatched ownership, and the ambiguous CREATE acknowledgement boundary. Other server releases and remote TLS connections were not verified locally. Grant escaping follows the [MySQL GRANT documentation](https://dev.mysql.com/doc/refman/8.4/en/grant.html); both `partial_revokes` branches have unit coverage, and MySQL was also verified with `partial_revokes=ON`, including denial of unconfigured parallel tokens and lookalike names.
