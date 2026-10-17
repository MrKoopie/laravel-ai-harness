# Development

This page is for contributors to the package itself.

## Requirements

- PHP 8.2 or newer.
- Composer 2.
- Bash and Python 3.11+ (`tomllib`) for generated Codex lifecycle replay tests.
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

- Configuration layering, process overrides, and parameter precedence.
- Every init setting, multiple agents, empty/false selections, and repeatable local overrides.
- Generated TOML parsing and setup/cleanup replay, including literal values and failure propagation.
- Command mapping.
- Resistance to argument injection.
- Safe writes to managed files.
- Bootstrap recovery.
- Herd and Sail composition.
- Host/Sail/Cloud MySQL cleanup with ownership checks, numeric worker matching, and failed-operation retries.
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

When adding a harness setting, update `ConfigLoader` defaults and environment mappings, the `Config::environment()` serializer, `InitCommand` parameter mapping/help, and the configuration/parameter reference tables together. Keep process exports ephemeral: generated agent settings carry them, while harness config files remain file-based defaults. Local JSON values must use the same validation and per-variable precedence as other local overrides.

Database lifecycle tests use PDO or executable boundary fixtures and do not connect to machine services. Replay tests parse generated TOML with `tomllib`, execute its scripts against fixture commands, and verify that setup/cleanup receive the same values. A passing fixture run is not proof that a live Codex environment was selected or that a machine service was provisioned.
