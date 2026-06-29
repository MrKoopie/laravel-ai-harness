# Laravel AI Harness

Laravel AI Harness installs and refreshes the files that keep AI agents useful in Laravel projects: agent instructions, MCP configuration, runtime helper scripts, workspace hooks, and quality-gate guidance.

## Usage

- Run `php artisan ai-harness:install` once after installing the package.
- Run `php artisan ai-harness:update` whenever harness files should be refreshed.
- Composer hooks added by `ai-harness:install` automatically run `ai-harness:update` after `composer install` and `composer update`.
- Use `php artisan ai-harness:doctor` to verify the selected harness surface exists.

@verbatim
<code-snippet name="Install Laravel AI Harness" lang="shell">
php artisan ai-harness:install
</code-snippet>

<code-snippet name="Install Laravel AI Harness With Optional Features" lang="shell">
php artisan ai-harness:install --with=docker --with=polyscope
</code-snippet>
@endverbatim

## Managed Files

- Keep project-specific notes outside AI Harness managed blocks.
- Do not manually edit package-owned generated files such as `.codex/hooks.json`, `.codex/environments/environment.toml`, `.codex/scripts/local-environment.sh`, `.dev/bin/ai-harness`, `.claude/settings.json`, or `.ai/mcp/mcp.json`; update package configuration or templates and rerun `ai-harness:update`.
- Treat Laravel Boost files separately from harness files. Boost owns the `<laravel-boost-guidelines>` block, while this package owns only AI Harness managed blocks and generated harness files.

## Optional Features

- `--with=docker` adds Docker-oriented helper files, including the MySQL testing database initialization script.
- `--with=polyscope` adds `polyscope.json`.
- Herd support is runtime detection in `.dev/bin/ai-harness`; it does not currently add separate Herd manifest files.
- Runtime drivers should prefer Sail when its Docker or Podman runtime is reachable, then Herd, then the bare local PHP runtime.

## Verification

After changing harness configuration, templates, drivers, or generated files, run the smallest relevant project tests plus:

@verbatim
<code-snippet name="Verify Laravel AI Harness" lang="shell">
php artisan ai-harness:doctor
</code-snippet>
@endverbatim
