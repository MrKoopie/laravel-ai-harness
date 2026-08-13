# Laravel AI Harness

Laravel AI Harness is a small Composer development tool that gives Codex and Claude one stable command for Laravel projects running through native PHP, Laravel Herd, or Laravel Sail.

All execution logic stays in the Composer package. A consuming project receives only a tiny `.ai-harness` bootstrap, a strict project configuration file, concise agent instructions, and native Codex/Claude lifecycle configuration.

## Requirements

- PHP 8.2 or newer.
- Composer 2.2 or newer.
- Bash on macOS, Linux, or WSL for the project bootstrap.
- Optional: Laravel Herd.
- Optional: Laravel Sail and Docker.

## Install

Add the package once and initialize the project files:

```bash
composer require --dev mrkoopie/laravel-ai-harness
./vendor/bin/ai-harness init
```

Commit the generated project files. From then on, people and coding agents use:

```bash
./.ai-harness doctor
```

When `vendor/bin/ai-harness` is missing, `.ai-harness` runs `composer install --no-interaction --prefer-dist`, falling back to `herd composer install` when Composer is not on `PATH`. It never adds or updates package requirements. After dependencies exist, the bootstrap executes Composer's `vendor/bin/ai-harness` proxy.

## Project Files

The default installation manages only:

```text
.ai-harness
.ai-harness.config
.gitignore                 # one managed block
AGENTS.md                  # one managed block
CLAUDE.md                  # one managed block
.codex/environments/environment.toml
.claude/settings.json      # merges only package-owned hooks
```

The package does not copy runtime executors, database scripts, skills, MCP configuration, or per-agent shell scripts into projects.

## Configuration

Configuration is strict `key=value` data:

```ini
runtime=herd
services=sail
agents=codex,claude

sail_services=mysql,redis,mailpit
herd_secure=true
herd_php=8.4
worktrees=true
```

Files are loaded from lowest to highest priority:

1. `.ai-harness.config.dist`
2. `.ai-harness.config`
3. `.ai-harness.config.local`

The local file and harness state file are ignored automatically. Unknown keys, invalid values, duplicate keys within one file, and oversized configuration files fail clearly. Configuration files are parsed as data and are never sourced as shell scripts.

### Runtimes

`runtime` selects where PHP-related commands execute:

| Runtime | Artisan | Composer | PHP | npm |
| --- | --- | --- | --- | --- |
| `native` | `php artisan` | `composer` | `php` | `npm` |
| `herd` | `herd php artisan` | `herd composer` | `herd php` | `npm` |
| `sail` | `sail artisan` | `sail composer` | `sail php` | `sail npm` |

There is no automatic runtime fallback. `doctor` reports when the configured runtime is unavailable.

### Services

`services=sail` lets Sail manage supporting containers independently of the PHP runtime. This supports both full Sail projects and Herd PHP with Sail-provided MySQL, Redis, or Mailpit.

An empty `sail_services` value starts and stops the full stack. A comma-separated list starts only those services; `down` then uses `sail stop` for that selected subset. With `runtime=sail`, the harness always includes Sail's `laravel.test` application container, even when `services=none`. The harness never deletes Docker volumes.

When Herd or native PHP uses Sail's MySQL service, `DB_PORT` follows the standard Sail `FORWARD_DB_PORT` value in `.env` (default `3306`). Set `FORWARD_DB_PORT=3307`, for example, when another local MySQL service already uses port 3306.

## Commands

```bash
./.ai-harness init
./.ai-harness doctor

./.ai-harness artisan migrate
./.ai-harness composer install
./.ai-harness php -v
./.ai-harness npm run build
./.ai-harness test --filter=ExampleTest

./.ai-harness up
./.ai-harness down
./.ai-harness setup
./.ai-harness cleanup
```

Runtime arguments are executed as an argument array, not through a shell command string. Options, spaces, and shell metacharacters are forwarded unchanged.

`init`, `doctor`, `setup`, `cleanup`, `up`, and `down` accept `--path=/path/to/project`; runtime commands operate in the current directory.

## Setup and Cleanup

`setup` performs only predictable local preparation:

1. Run Composer install when the target checkout has no `vendor/autoload.php`.
2. Copy `.env.example` to `.env` when `.env` is absent.
3. Configure MySQL values when Sail manages its `mysql` service, using `127.0.0.1` for native or Herd runtime and `mysql` for Sail runtime.
4. Start configured Sail services.
5. Link the directory in Herd when `runtime=herd` and set its path-derived HTTPS URL.
6. Secure the Herd site and isolate its PHP version when configured.
7. Generate `APP_KEY` when the project has an empty key.
8. Create `.env.testing` when absent, using Sail's `testing` database for MySQL or isolated SQLite defaults otherwise; update the default SQLite entries in `phpunit.xml` to Sail's MySQL testing database.

It does not run migrations, create or drop databases, rewrite `compose.yaml`, or inject test-runner options. Its only PHPUnit change is converting Laravel's default SQLite database entries when Sail manages MySQL.

`cleanup` only removes HTTPS and unlinks a Herd site previously recorded as harness-owned. Before each action, it verifies that the recorded site name is the deterministic name for the current project path. Sail shutdown is always the explicit `down` command.

## Codex

`init` adds concise runtime instructions to `AGENTS.md`. With `worktrees=true`, it also writes one Codex local environment whose setup and cleanup scripts call `./.ai-harness setup` and `./.ai-harness cleanup` directly.

There are no duplicate SessionStart fallbacks or Codex-specific executor scripts. Select the generated `Laravel AI Harness` local environment in Codex when creating a worktree.

## Claude Code

`init` adds concise runtime instructions to `CLAUDE.md`. With `worktrees=true`, it merges four package-owned command hooks into `.claude/settings.json`:

- `SessionStart` prepares an existing Claude worktree.
- `PostToolUse` with `EnterWorktree` prepares the worktree reported by Claude.
- `PreToolUse` with `ExitWorktree` cleans the worktree before removal.
- `WorktreeRemove` cleans `--worktree` and isolated-subagent worktrees before Claude removes them.

Every hook calls `.ai-harness hook claude ...`; JSON payload parsing and lifecycle logic stay in the Composer package. Existing settings and unrelated hooks are preserved. Disabling worktree automation removes only the package-owned hooks on the next `init`.

## Git Scope

The harness does not create, remove, update, or select Git branches or worktrees. It never runs `git fetch`, `pull`, `checkout`, `switch`, `branch`, `rebase`, or `worktree`. Codex or Claude provides the current directory; the harness only prepares that directory.

## Development

```bash
composer install
composer test
composer format:check
composer analyse
composer shellcheck
composer validate --strict
```

The test suite covers configuration layering, command mapping, argument-injection resistance, safe managed-file writes, bootstrap recovery, Herd/Sail composition, ownership-checked cleanup, Claude hook payloads, Codex/Claude installation, and the no-Git invariant.
