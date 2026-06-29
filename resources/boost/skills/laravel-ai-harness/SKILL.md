---
name: laravel-ai-harness
description: Use when installing, updating, extending, or debugging Laravel AI Harness files, drivers, Composer hooks, MCP configuration, or generated agent guidance.
---

# Laravel AI Harness

Use this skill when work touches Laravel AI Harness installation, generated AI agent files, runtime drivers, optional workspace features, or package quality gates.

## Core Workflow

- Install once with `php artisan ai-harness:install`.
- Refresh package-owned files with `php artisan ai-harness:update`.
- Verify the selected surface with `php artisan ai-harness:doctor`.
- Preserve optional feature flags in install/update commands, for example `--with=docker --with=polyscope`.
- When Composer hooks are enabled, keep the guarded `ai-harness:update --ansi` hook in both `post-install-cmd` and `post-update-cmd`.

## Managed File Boundaries

- Keep custom project guidance outside AI Harness managed blocks.
- Do not hand-edit package-owned generated files. Change package configuration, source templates, or drivers, then rerun `ai-harness:update`.
- Keep Laravel Boost output separate. Boost replaces its `<laravel-boost-guidelines>` block; AI Harness replaces only its own managed blocks and generated harness files.
- If both Boost and AI Harness are installed, run `php artisan boost:update --discover` for Boost resources and `php artisan ai-harness:update` for harness resources.

## Drivers And Optional Features

- Agent drivers describe which agent surfaces receive harness guidance, such as Codex and Claude.
- Runtime drivers describe how commands run locally, preferring Sail when its Docker or Podman runtime is reachable, then Herd, then bare PHP.
- `--with=docker` installs Docker-oriented helper files.
- `--with=polyscope` installs Polyscope configuration.
- Herd support is runtime detection in `.dev/bin/ai-harness`; it does not currently create a separate Herd file.

## Quality Gates

When changing this package, run the relevant tests and quality tools:

```bash
composer test
composer analyse
vendor/bin/pint --test --format agent
composer validate --strict
```

When validating in a host Laravel application, run:

```bash
php artisan ai-harness:doctor
php artisan test
```
