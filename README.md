# Laravel AI Harness

Laravel AI Harness is a small Composer development tool that gives Codex and Claude one stable command for Laravel projects running through native PHP, Laravel Herd, or Laravel Sail.

Application lifecycle logic stays in the Composer package. A consuming project receives a tiny `.ai-harness` bootstrap, an optional cloud provisioning helper, a strict project configuration file, concise agent instructions, and native Codex/Claude lifecycle configuration.

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

`init` adds one guarded, package-owned command to Composer's root `post-install-cmd` and `post-update-cmd` events. Future Composer installs and updates therefore refresh the managed integration files automatically. Existing project scripts are preserved, and repeated initialization does not add duplicates. The hook exits successfully when Composer runs with `--no-dev` or the development package binary is unavailable.

Package installation and project refresh are deliberately separate operations:

1. `composer update mrkoopie/laravel-ai-harness` downloads a newer package release.
2. `./.ai-harness update` refreshes the project files from the currently installed release.

For recovery or debugging, run Composer with `--no-scripts`, then refresh explicitly:

```bash
composer update mrkoopie/laravel-ai-harness --no-scripts
./vendor/bin/ai-harness update
```

When recovering an upgrade from v0.1, use the temporary Artisan compatibility bridge instead so the old configuration and environment choices are migrated before the new project files are created:

```bash
composer update mrkoopie/laravel-ai-harness --no-scripts
php artisan ai-harness:update --ansi
```

When `vendor/bin/ai-harness` is missing, `.ai-harness` runs `composer install --no-interaction --prefer-dist`, falling back to `herd composer install` when Composer is not on `PATH`. It never adds or updates package requirements. After dependencies exist, the bootstrap executes Composer's `vendor/bin/ai-harness` proxy.

## Project Files

The default installation manages only:

```text
.ai-harness
.ai-harness-cloud          # cloud=true: provisioning and lifecycle entrypoint
.ai-harness.config
.gitignore                 # one managed block
AGENTS.md                  # one managed block
.codex/environments/environment.toml
.claude/settings.json      # merges only package-owned hooks
composer.json              # two guarded package-owned script entries
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

When Herd or native PHP uses Sail's MySQL service, `DB_PORT` follows the standard Sail `FORWARD_DB_PORT` value in `.env` (default `3306`). Set `FORWARD_DB_PORT=3307`, for example, when another local MySQL service already uses port 3306. The harness derives a MySQL-safe database name from the checkout path plus a short hash, and uses a `_testing` suffix for the isolated test database. Names leave room for Laravel's parallel worker suffix within MySQL's 64-character limit. This makes every worktree distinct without querying Git or agent metadata.

## Commands

```bash
./.ai-harness init
./.ai-harness update
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

`init`, `update`, `doctor`, `setup`, `cleanup`, `up`, and `down` accept `--path=/path/to/project`; runtime commands operate in the current directory.

`init` and `update` only synchronize project integration files. They do not install dependencies, start containers, create databases, link Herd sites, run migrations, or inspect or update Git. Environment work happens through `setup`, `cleanup`, `up`, `down`, and the explicit cloud commands below.

## Setup and Cleanup

Outside a detected cloud environment, `setup` performs predictable local preparation:

1. Run Composer install when the target checkout has no `vendor/autoload.php`.
2. Copy `.env.example` to `.env` when `.env` is absent.
3. Configure and normalize MySQL values when Sail manages its `mysql` service, using `127.0.0.1` for native or Herd runtime and `mysql` for Sail runtime. Commented database defaults are activated rather than duplicated.
4. Start configured Sail services and ensure the checkout-specific development and testing databases exist.
5. Link the directory in Herd when `runtime=herd` and set its path-derived HTTPS URL.
6. Secure the Herd site and isolate its PHP version when configured.
7. Generate `APP_KEY` when the project has an empty key.
8. Create `.env.testing` when absent, using Sail's `testing` database for MySQL or isolated SQLite defaults otherwise; update the default SQLite entries in `phpunit.xml` to Sail's MySQL testing database.

It does not run migrations, rewrite `compose.yaml`, or inject test-runner options. It creates only its checkout-specific Sail MySQL databases and updates PHPUnit's selected test database when Sail manages MySQL. `cleanup` drops only those deterministic, harness-owned databases after validating the recorded Herd site; Codex worktree cleanup then stops the configured Sail services without deleting Docker volumes.

MySQL cleanup also removes this checkout's numeric parallel test databases (including Laravel's `_test_1` form), but preserves similarly named databases without a numeric worker suffix and databases belonging to other checkouts. It retains ownership state if database cleanup fails, so cleanup can be retried.

`cleanup` only removes HTTPS and unlinks a Herd site previously recorded as harness-owned. Before each action, it verifies that the recorded site name is the deterministic name for the current project path. Sail shutdown is always the explicit `down` command.

## Agent instructions and Codex

`init` adds shared runtime instructions to `AGENTS.md` when Codex or Claude is enabled. With `worktrees=true` and Codex enabled, it also writes one Codex local environment whose setup and cleanup scripts call `./.ai-harness setup` and `./.ai-harness cleanup` directly. `.codex/environments/environment.toml` is package-owned and overwritten during each refresh; put custom Codex configuration elsewhere.

There are no duplicate SessionStart fallbacks or Codex-specific executor scripts. Select the generated `Laravel AI Harness` local environment in Codex when creating a worktree.

## Claude Code

Claude Code v2.1.277 or later reads `AGENTS.md` by default when no project `CLAUDE.md` or `CLAUDE.local.md` takes precedence. The harness no longer creates `CLAUDE.md`; `update` removes its legacy managed block and deletes the file only if nothing else remains. If you keep your own `CLAUDE.md`, add `@AGENTS.md` to it or configure Claude to read both files. Some Claude sessions, including those on third-party providers or with telemetry disabled, still need that import. See [Claude Code's instruction-file guidance](https://code.claude.com/docs/en/memory#agentsmd).

With `worktrees=true`, the harness merges four package-owned command hooks into `.claude/settings.json`:

- `SessionStart` prepares an existing Claude worktree.
- `PostToolUse` with `EnterWorktree` prepares the worktree reported by Claude.
- `PreToolUse` with `ExitWorktree` cleans the worktree before removal.
- `WorktreeRemove` cleans `--worktree` and isolated-subagent worktrees before Claude removes them.

Every hook calls `.ai-harness hook claude ...`; JSON payload parsing and lifecycle logic stay in the Composer package. Existing settings and unrelated hooks are preserved. Disabling worktree automation removes the worktree-specific hooks on the next refresh. SessionStart and SessionEnd remain while cloud automation is enabled; disable both `worktrees` and `cloud` to remove all harness hooks.

## Cloud Environments

Run `./.ai-harness update` in each consuming project and commit the generated
`.ai-harness-cloud`, `.ai-harness`, agent instructions, and Claude settings.
The cloud helper can provision system tools before `vendor/` exists. Cloud
execution always uses native PHP rather than the project's local Herd/Sail runtime.

### Detection and configuration

1. Set `AI_HARNESS_ENV=codex-cloud` in Codex cloud environment variables.
2. Claude automatically uses its documented `CLAUDE_CODE_REMOTE=true` signal.
   Set `AI_HARNESS_ENV=claude-cloud` in the environment as well when using the
   provisioning script, which runs before the agent starts.
3. An explicit `AI_HARNESS_ENV=local` overrides automatic detection. Unknown
   values fail clearly. Linux, checkout paths, and installed agent binaries do
   not imply cloud execution.

Cloud options in `.ai-harness.config` are independent of `worktrees`:

```ini
cloud=true
cloud_services=mysql,redis
cloud_migrate=false
cloud_seed=false
cloud_build=false
cloud_browser=false
```

The default service is `mysql`. Set `cloud_services=` for SQLite and no managed
services. Redis uses the PHP Redis extension. Migrations are opt-in and run
`artisan migrate` for development and testing; no `migrate:fresh` runs. Seeding
requires migrations enabled and invokes the development seeder on every setup,
so enable it only with seeders that are safe to rerun. Builds use `npm run build`.
Browser installation requires Playwright already declared in project dependencies
and installs Chromium plus its OS dependencies. These options never affect local setup.

### Claude cloud

1. Set the environment variables above and configure the environment setup script
   to run `./.ai-harness-cloud provision` from the repository root. This installs
   system dependencies and benefits from the provider's filesystem cache.
2. The generated repository `SessionStart` hook runs project setup for ordinary
   cloud clones, including resumes, even with `worktrees=false`. It installs the
   current locked dependencies and starts/checks services on every session.
3. The generated `SessionEnd` hook performs test-only cleanup with a 60-second
   timeout. Cleanup is retryable with `./.ai-harness cloud cleanup`.
4. Multi-repository Claude sessions do not load repository hooks. Run
   `./.ai-harness-cloud setup` explicitly in each checkout. Do not rely on a
   cached provisioning script to restart services.

Claude's setup budget is approximately five minutes. Put system provisioning in
the environment setup script and project setup in SessionStart. Package installs
need the appropriate registry/archive hosts in the environment network allowlist.
See [Claude cloud environments](https://code.claude.com/docs/en/cloud-environments)
and [SessionEnd hooks](https://code.claude.com/docs/en/hooks#sessionend).

### Codex cloud

Set `AI_HARNESS_ENV=codex-cloud` as an **environment variable**, then configure
these commands in the cloud environment UI, running from the repository root:

```bash
# Setup script: system provisioning followed by project preparation.
./.ai-harness-cloud provision
./.ai-harness-cloud setup
```

```bash
# Maintenance script: reconcile the selected branch after restoring a cache.
./.ai-harness-cloud maintain
```

The generated `.codex/environments/environment.toml` remains a **local worktree**
integration; it does not configure the cloud environment UI. Both setup and
maintenance must be wired there. Setup has internet access; dependency refreshes
need network access in the phase where they execute. Install required test tools
before the agent phase if agent internet access is disabled.

Codex environment secrets are setup-only. Use them for private dependency
authentication during setup, and ensure maintenance can reinstall branch-specific
dependencies without assuming those secrets remain available. Do not persist
package credentials in tracked files. No guaranteed Codex cloud teardown hook is
assumed; run `./.ai-harness cloud cleanup` explicitly when useful, and allow the
provider to discard its ephemeral container. See
[Codex cloud environments](https://learn.chatgpt.com/docs/environments/cloud-environment).

### Preparation and boundaries

1. Provisioning requires Ubuntu/Debian, root or passwordless `sudo`, and apt
   access. It selects the distribution's default PHP CLI (minimum 8.2), installs
   common Laravel extensions, Composer,
   MySQL, Redis, and missing Node/npm. Set `AI_HARNESS_PHP_VERSION=8.4`, for
   example, only if that version exists in the environment's configured apt
   repositories. Images whose default PHP is older than 8.2 need a compatible
   image or an explicit available version. The harness never adds third-party
   apt repositories. Pin Node
   in the provider image/settings to satisfy the project's `engines` requirement.
   When present, provisioning uses `ubuntu.sources` or `debian.sources` alone,
   avoiding unrelated image repositories that the cloud proxy may block. Set
   `AI_HARNESS_APT_SOURCE_LIST` to an existing absolute source-list path to
   override that choice. Package signature verification remains enabled. If the
   image puts phpenv shims first in `PATH`, select `/usr/bin` first in setup and
   maintenance to use the provisioned PHP and its installed extensions.
2. Project setup requires a committed `composer.lock`, installs development
   dependencies and runs `composer check-platform-reqs`. It does not skip platform
   requirements. Frontend projects require `package-lock.json` and use
   `npm ci --include=dev`; other package managers need project-specific setup.
3. `AI_HARNESS_COMPOSER_PREFER=source` opts into Git source installs when archive
   downloads are blocked. It applies to the initial bootstrap too. This is an
   explicit network workaround, not a blanket fallback or a TLS bypass.
4. Setup replaces standard Laravel database/Redis connection settings with local
   cloud values, clears the default config cache, normalizes `APP_CONFIG_CACHE`,
   and reconciles inline PHPUnit connection overrides. Inherited standard
   connection variables are removed for harness-run application commands. Custom
   application connection names/configuration remain the application's responsibility.
5. MySQL administration uses only the local Unix socket, ignores user option/login
   files, and creates a checkout-specific development/testing pair plus a scoped
   localhost application user. Its fixed `harness` password is for disposable
   development containers only. `AI_HARNESS_MYSQL_SOCKET` can select another
   absolute local socket; the same socket is written into Laravel configuration.
6. Cloud cleanup requires recorded ownership, preserves the development database,
   and drops only the exact testing name and numeric `<testing>_1` /
   `<testing>_test_1` worker forms. Similar names, backups and other checkouts are
   excluded. Ownership stays recorded so failed or repeated cleanup can be retried.
7. Keep required install failures visible. A passing local suite does not verify
   a provider's actual image, network allowlist or cache lifecycle: validate a
   fresh start, cached start and resume in each configured cloud environment.

## Laravel Boost

When Laravel Boost is installed in the consuming application, it can discover this package's short guideline at `resources/boost/guidelines/core.blade.php`. That guideline explains configuration and environment boundaries; the AI Harness managed block in `AGENTS.md` remains the source for command syntax. No Boost dependency or additional project file is required by AI Harness.

## Upgrading From 0.1

Version 0.2 retains the old `artisan ai-harness:update` command and service-provider class for one release so an existing v0.1 Composer hook can complete the upgrade. That compatibility command replaces the old hook with the new guarded `./vendor/bin/ai-harness update` hook.

When no `.ai-harness.config`, `.dist`, or `.local` file exists, the bridge translates the old Codex, Claude, Herd, Docker, and PHP-version choices. Old Docker support becomes `services=sail` with `sail_services=mysql`. Unsupported skills and Polyscope generation, plus obsolete generated files, produce explicit warnings and are not silently deleted. Existing new-format configuration always wins.

After the first successful refresh, use `./.ai-harness update`; the Artisan compatibility command is temporary and may be removed in 0.3.

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
