# Laravel AI Harness

Laravel AI Harness installs and refreshes the files that make AI agents useful in a Laravel project: agent instructions, MCP configuration, local runtime helpers, worktree setup hooks, and repeatable quality guidance.

The package is intentionally conservative. Human-editable files receive managed blocks, generated scripts/config files are package-owned, and optional tooling such as Docker and Polyscope is opt-in.

## Installation

```bash
composer require yourwebhoster/laravel-ai-harness
php artisan ai-harness:install
```

The install command writes the initial harness files and adds this command to both Composer lifecycle hooks:

```bash
@php artisan ai-harness:update --ansi
```

After that first install, Composer will refresh managed harness files on every `composer install` and `composer update`.

When `ai-harness:install` is run with `--with=*` feature flags, those flags are preserved in the Composer hook.

## Commands

```bash
php artisan ai-harness:install
php artisan ai-harness:update
php artisan ai-harness:doctor
```

`ai-harness:install` writes the harness and patches Composer scripts.

`ai-harness:update` refreshes package-managed files and blocks.

`ai-harness:doctor` checks whether the selected harness surface exists.

All commands accept `--path=/path/to/project` for testing or non-standard project roots.

## Optional Features

Codex, Claude, shared MCP configuration, and the generated harness skill are enabled by default.

Docker and Polyscope are opt-in:

```bash
php artisan ai-harness:install --with=docker --with=polyscope
php artisan ai-harness:update --with=docker --with=polyscope
```

Feature defaults can also be configured:

```dotenv
AI_HARNESS_CODEX=true
AI_HARNESS_CLAUDE=true
AI_HARNESS_SKILLS=true
AI_HARNESS_HERD=false
AI_HARNESS_DOCKER=false
AI_HARNESS_POLYSCOPE=false
```

## Laravel Boost

This package ships Laravel Boost resources for package discovery:

- `resources/boost/guidelines/core.blade.php`
- `resources/boost/skills/laravel-ai-harness/SKILL.md`

After installing Laravel Boost, run `php artisan boost:install` or `php artisan boost:update --discover` and select `yourwebhoster/laravel-ai-harness` when Boost asks for third-party guidelines or skills.

Boost and AI Harness own different generated regions. Boost refreshes its `<laravel-boost-guidelines>` block, while AI Harness refreshes its own `<!-- ai-harness:start -->` blocks and generated harness files.

## Generated Files

Default files:

- `AGENTS.md`
- `CLAUDE.md`
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

## Development

```bash
composer install
composer test
composer analyse
vendor/bin/pint --test --format agent
composer validate --strict
```

PHPStan uses Larastan. Run it through `composer analyse`, which passes `--memory-limit=1G` for Laravel package analysis.

## Documentation

- [Feature Set](docs/feature-set.md)
- [Drivers](docs/drivers.md)
- [Managed Files](docs/managed-files.md)
