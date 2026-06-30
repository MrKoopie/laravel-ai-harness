# Managed Files

The package uses two ownership modes.

## Managed Blocks

`AGENTS.md` and `CLAUDE.md` are human-editable files, so the package writes only this block:

```md
<!-- ai-harness:start -->
...
<!-- ai-harness:end -->
```

`.gitignore` uses the same ownership model with Gitignore-safe comments:

```gitignore
# ai-harness:start
...
# ai-harness:end
```

The generated `.gitignore` block unignores only package-managed harness artifacts under `.codex`, `.claude`, `.ai`, `.agents`, and `.dev`. Laravel skeletons can ignore `/.codex` by default, so keep this block committed before creating agent worktrees.

`.codex/config.toml` also uses a managed block with TOML-safe `# ai-harness:start` / `# ai-harness:end` comments. Existing Codex project settings outside that block belong to the project and are preserved by updates.

Content outside managed blocks belongs to the project and is preserved by updates.

## Managed Files

Scripts, MCP config, generated skill files, and optional workspace config are package-owned files. Updates replace the whole file.

Current managed files include:

- `.ai/mcp/mcp.json`
- `.dev/bin/ai-harness`
- `.codex/environments/environment.toml`
- `.codex/hooks.json`
- `.codex/scripts/local-environment.sh`
- `.claude/settings.json`
- `.claude/scripts/worktree-up.sh`
- `.claude/scripts/worktree-down.sh`
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
ai-harness:update --ansi --with=docker --with=herd --with=polyscope
```
