# Managed Files

The package uses two ownership modes.

## Managed Blocks

`AGENTS.md` and `CLAUDE.md` are human-editable files, so the package writes only this block:

```md
<!-- ai-harness:start -->
...
<!-- ai-harness:end -->
```

Content outside the block belongs to the project and is preserved by updates.

## Managed Files

Scripts, MCP config, generated skill files, and optional workspace config are package-owned files. Updates replace the whole file.

Current managed files include:

- `.ai/mcp/mcp.json`
- `.dev/bin/ai-harness`
- `.codex/config.toml.example`
- `.codex/environments/environment.toml`
- `.codex/hooks.json`
- `.codex/scripts/local-environment.sh`
- `.claude/settings.json`
- `.agents/skills/laravel-ai-harness/SKILL.md`
- `.claude/skills/laravel-ai-harness/SKILL.md`
- `docker/mysql/init/10-create-testing-database.sh`
- `polyscope.json`

Keep project-specific notes in user-owned files or outside managed blocks.

## Composer Auto Update

`ai-harness:install` adds a guarded `ai-harness:update --ansi` command to both:

- `post-install-cmd`
- `post-update-cmd`

The hook is appended once, existing Composer scripts are preserved, and the guard skips the update when the package is absent during `composer install --no-dev`.

If install is run with optional features, the generated hook keeps those flags:

```bash
ai-harness:update --ansi --with=docker --with=polyscope
```
