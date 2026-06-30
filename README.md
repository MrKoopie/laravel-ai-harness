# Laravel AI Harness

Laravel AI Harness installs and refreshes the files that make AI agents useful in a Laravel project: agent instructions, MCP configuration, local runtime helpers, worktree setup hooks, and repeatable quality guidance.

The package is intentionally conservative. Human-editable files receive managed blocks, generated scripts/config files are package-owned, and optional workspace features such as Herd linking, Docker database bootstrap files, and Polyscope metadata are opt-in.

## Requirements

- PHP `^8.2`
- Laravel / Illuminate `^11.0`, `^12.0`, or `^13.0`
- Composer 2
- Optional: Laravel Sail, Laravel Herd, Docker or Podman, and Polyscope

## Installation

```bash
composer require mrkoopie/laravel-ai-harness --dev
php artisan ai-harness:install
```

The install command writes the initial harness files and adds this guarded command to Composer `post-install-cmd` and `post-update-cmd`:

```bash
@php -r "if (file_exists('vendor/mrkoopie/laravel-ai-harness')) { passthru(escapeshellarg(PHP_BINARY).' artisan ai-harness:update --ansi', $code); exit($code); }"
```

After the first install, Composer refreshes managed harness files on every `composer install` and `composer update`. The guard lets production `composer install --no-dev` skip the harness when the package is installed as a development dependency.

## Where To Configure Everything

Most teams configure the harness in three places:

- `config/ai-harness.php`: publish it when you want committed package defaults.
- `.env`: use `AI_HARNESS_*` variables for local or environment-specific overrides.
- User-owned guidance outside managed blocks in `AGENTS.md` and `CLAUDE.md`: keep project-specific rules there so updates do not replace them.
- `.gitignore`: keep the generated AI Harness unignore block so package-managed `.codex`, `.claude`, `.ai`, `.agents`, and `.dev` files can be committed.

Publish the config file with Laravel's vendor publishing workflow:

```bash
php artisan vendor:publish --tag=ai-harness-config
```

The config file controls default features, project naming, generated database names, PHP version hints, and the default worktree base ref. One-off features can also be selected per run:

```bash
php artisan ai-harness:install --with=herd --with=docker --with=polyscope
php artisan ai-harness:update --with=herd --with=docker --with=polyscope
```

When `ai-harness:install` is run with `--with=*` flags, those flags are preserved in the Composer hook.

## Defaults

These features are enabled by default:

| Feature | Default | What It Does |
| --- | --- | --- |
| Base guidance | Always on | Writes managed blocks to `AGENTS.md`, `CLAUDE.md`, and `.gitignore`; writes shared MCP config to `.ai/mcp/mcp.json`; writes the runtime helper to `.dev/bin/ai-harness`. |
| Codex | `AI_HARNESS_CODEX=true` | Writes `.codex/config.toml.example`, `.codex/environments/environment.toml`, `.codex/hooks.json`, and `.codex/scripts/local-environment.sh`. Codex setup copies `.env.example` to `.env`, configures a per-worktree `APP_URL`, app database, and companion testing database, runs `composer install` when `vendor/` is missing, generates `APP_KEY` when needed, runs migrations, and runs `ai-harness:doctor`. Codex cleanup removes the isolated app and testing databases when they are owned by the worktree. |
| Claude | `AI_HARNESS_CLAUDE=true` | Writes `.claude/settings.json` with a session-start doctor check through `.dev/bin/ai-harness`, plus Claude-local harness skill files when skills are enabled. |
| Skills | `AI_HARNESS_SKILLS=true` | Writes local skill documentation to `.agents/skills/laravel-ai-harness/SKILL.md` and `.claude/skills/laravel-ai-harness/SKILL.md`. |

These features are disabled by default:

| Feature | Default | What It Does When Enabled |
| --- | --- | --- |
| Herd workspace automation | `AI_HARNESS_HERD=false` | Codex setup links the generated worktree in Laravel Herd with a deterministic site name, sets `APP_URL` to that site, provisions isolated app/testing databases, and Codex cleanup removes those owned databases and unlinks the site. The runtime helper can still use `herd php artisan` as a fallback even when this automation is disabled. |
| Docker database bootstrap | `AI_HARNESS_DOCKER=false` | Writes `docker/mysql/init/10-create-testing-database.sh` for creating the testing database with the configured charset and collation. |
| Polyscope | `AI_HARNESS_POLYSCOPE=false` | Writes `polyscope.json` workspace metadata. |

## Configuration Reference

```dotenv
AI_HARNESS_CODEX=true
AI_HARNESS_CLAUDE=true
AI_HARNESS_SKILLS=true
AI_HARNESS_HERD=false
AI_HARNESS_DOCKER=false
AI_HARNESS_POLYSCOPE=false
```

Generated project metadata is derived from existing harness context before falling back to the directory name. Pin it when the generated names should not change across machines or worktree paths:

```dotenv
AI_HARNESS_PROJECT_NAME="bill-it"
AI_HARNESS_PROJECT_SLUG="bill-it"
AI_HARNESS_DATABASE_NAME="bill_it"
AI_HARNESS_DATABASE_CHARSET="utf8mb4"
AI_HARNESS_DATABASE_COLLATION="utf8mb4_uca1400_ai_ci"
AI_HARNESS_PHP_VERSION="8.3"
AI_HARNESS_WORKTREE_BASE_REF="origin/main"
```

`AI_HARNESS_WORKTREE_BASE_REF` defaults to the repository's `origin/HEAD` target when available, then `origin/main`.

## Commands

```bash
php artisan ai-harness:install
php artisan ai-harness:update
php artisan ai-harness:doctor
```

`ai-harness:install` writes the harness and patches Composer scripts.

`ai-harness:update` refreshes package-managed files and blocks.

`ai-harness:doctor` checks whether the selected harness surface exists and prints the registered agent/runtime drivers.

All commands accept `--path=/path/to/project` for validation apps, tests, or non-standard project roots.

## Development Workflow

For a Laravel application using this package:

1. Install the package with Composer and run `php artisan ai-harness:install`.
2. Publish `config/ai-harness.php` if the team wants committed defaults.
3. Put local overrides in `.env`.
4. Keep custom instructions outside the `<!-- ai-harness:start -->` / `<!-- ai-harness:end -->` blocks.
5. Commit the generated harness files before creating Codex or Claude worktrees. Git worktrees are created from a commit, so an uncommitted package install in the main checkout will not exist in new worktrees.
6. Run `php artisan ai-harness:doctor` after changing feature flags.
7. Let Composer refresh managed files during `composer install` and `composer update`, or run `php artisan ai-harness:update` manually after package upgrades.

For package development in this repository:

```bash
composer install
composer test
composer analyse
composer format:check
composer validate --strict
```

PHPStan uses Larastan. Run it through `composer analyse`, which passes `--memory-limit=1G` for Laravel package analysis.

The GitHub Actions workflow runs Pest against Laravel 11, 12, and 13 dependency lines, includes PHP 8.5 coverage on the latest Laravel line, then runs Composer validation, Pint, and PHPStan in a separate quality job.

## Laravel Sail Compatibility

The generated `.dev/bin/ai-harness` helper detects Sail first. When `./vendor/bin/sail` exists and Docker or Podman is running, harness commands execute through Sail:

```bash
./.dev/bin/ai-harness ai-harness:doctor
```

That command resolves to:

```bash
./vendor/bin/sail artisan ai-harness:doctor
```

If Sail is unavailable, the helper falls back to `herd php artisan` when Herd exists, then to plain `php artisan`.

Sail projects do not need a special install path:

```bash
composer require mrkoopie/laravel-ai-harness --dev
php artisan ai-harness:install
./vendor/bin/sail artisan ai-harness:doctor
./vendor/bin/sail test
```

The package does not rewrite `compose.yaml`. If you enable the Docker database bootstrap file with `--with=docker`, mount or copy `docker/mysql/init/10-create-testing-database.sh` into your Sail MySQL service only if your project wants MySQL to create the testing database during container startup.

## Herd Worktree Automation

Herd workspace automation is opt-in:

```bash
php artisan ai-harness:install --with=herd
```

With Herd enabled, Codex setup links the worktree using a deterministic name based on the worktree directory, its parent directory, and a checksum of the full worktree path. Codex setup also sets `APP_URL` to that Herd site, configures an isolated app database, creates a companion testing database, runs migrations, and runs the harness doctor check. The testing database name is written to `.env` as `AI_HARNESS_TEST_DB_DATABASE` for projects that want to point `.env.testing` or their test bootstrap at the generated database. Codex cleanup removes both isolated databases when they match the generated worktree names and unlinks the same Herd site. This keeps temporary Codex worktrees addressable in Herd without leaving stale Herd links or databases after teardown.

For SQLite projects, the isolated app and testing databases are generated files under `database/`. For MySQL or MariaDB projects, the setup hook creates generated database names using the configured database base name plus the worktree checksum, and cleanup drops only those generated database names.

The runtime helper can still use Herd for Artisan commands without enabling workspace automation:

```bash
./.dev/bin/ai-harness migrate --env=testing
```

When Sail is not active and Herd is installed, this resolves to:

```bash
herd php artisan migrate --env=testing
```

## Generated Files

Default files:

- `AGENTS.md`
- `CLAUDE.md`
- `.gitignore` managed unignore block
- `.ai/mcp/mcp.json`
- `.dev/bin/ai-harness`
- `.codex/config.toml.example`
- `.codex/environments/environment.toml`
- `.codex/hooks.json`
- `.codex/scripts/local-environment.sh`
- `.claude/settings.json`
- `.agents/skills/laravel-ai-harness/SKILL.md`
- `.claude/skills/laravel-ai-harness/SKILL.md`

Optional files:

- `docker/mysql/init/10-create-testing-database.sh`
- `polyscope.json`

## Laravel Boost

This package ships Laravel Boost resources for package discovery:

- `resources/boost/guidelines/core.blade.php`
- `resources/boost/skills/laravel-ai-harness/SKILL.md`

After installing Laravel Boost, run `php artisan boost:install` or `php artisan boost:update --discover` and select `mrkoopie/laravel-ai-harness` when Boost asks for third-party guidelines or skills.

Boost and AI Harness own different generated regions. Boost refreshes its `<laravel-boost-guidelines>` block, while AI Harness refreshes its own `<!-- ai-harness:start -->` blocks and generated harness files.

## Documentation

- [Feature Set](docs/feature-set.md)
- [Drivers](docs/drivers.md)
- [Managed Files](docs/managed-files.md)
