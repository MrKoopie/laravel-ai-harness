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
