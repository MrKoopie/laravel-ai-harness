# Drivers

Drivers keep the package extensible. The current implementation includes two driver families.

## Agent Drivers

Agent drivers describe how an AI coding agent consumes project guidance and skills.

`codex`

- Purpose: installs and refreshes `AGENTS.md` plus `.agents/skills`.
- Why it exists: Codex reads repository guidance and skills from these paths.

`claude`

- Purpose: installs and refreshes `CLAUDE.md` plus `.claude/skills`.
- Why it exists: Claude Code uses its own project guidance and skill locations.

`cursor`

- Purpose: reserves a generic editor-agent driver shape using `AGENTS.md` and `.cursor/skills`.
- Why it exists: the package should not be hard-coded to only Codex and Claude.

## Runtime Drivers

Runtime drivers describe how the harness should execute Laravel commands in the current environment.

`bare`

- Purpose: run Artisan through local PHP.
- Command shape: `php artisan ...`
- Best for: plain local PHP or CI containers.

`herd`

- Purpose: run Artisan through Laravel Herd's PHP.
- Command shape: `herd php artisan ...`
- Best for: macOS Herd projects where Herd owns the active PHP version.
- Generated files: none beyond the default Codex hook. The generated `.dev/bin/ai-harness` helper auto-detects Herd as a fallback when Sail is unavailable. When `AI_HARNESS_HERD=true` or `--with=herd` is used, the generated Codex setup hook links the temporary worktree in Herd and cleanup unlinks it.

`sail`

- Purpose: run Artisan through Laravel Sail.
- Command shape: `./vendor/bin/sail artisan ...`
- Best for: Docker-backed Laravel projects using Sail while Docker or Podman is running.

## Optional Workspace Features

Some integrations are feature flags rather than always-on files.

`docker`

- Purpose: generate a MySQL init script for the test database.
- Opt in with: `--with=docker` or `AI_HARNESS_DOCKER=true`.

`polyscope`

- Purpose: generate `polyscope.json` for workspace/task metadata.
- Opt in with: `--with=polyscope` or `AI_HARNESS_POLYSCOPE=true`.

## Adding A Driver

Add a contract implementation, register it in `DriverRegistry`, then add manifest entries only when the driver needs generated files.

Generated files should be optional unless every Laravel project benefits from them.
