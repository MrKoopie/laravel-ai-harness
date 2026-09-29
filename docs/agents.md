# Coding agents

The harness integrates with Codex and Claude Code. Select the agents with the `agents` key. Refer to [Configuration](configuration.md#agents).

## Shared instructions in `AGENTS.md`

When `codex` or `claude` is in `agents`, `init` and `update` add one managed block to `AGENTS.md`. The block tells agents to:

- Run Laravel, test, Composer, PHP, and npm commands through `./.ai-harness`.
- Use `up`, `down`, and `doctor` for the local environment.
- Not bypass the configured runtime unless the user asks.
- Run Herd commands outside the Codex sandbox.
- Use the cloud commands in cloud sessions.

The block starts with `<!-- ai-harness:start -->`. Do not edit the text inside the block. The next refresh replaces it. Put your own instructions above or below the block.

## Codex

### Local worktrees

With `codex` in `agents` and `worktrees=true`, the harness writes `.codex/environments/environment.toml`. This file defines a Codex local environment named `Laravel AI Harness`:

```toml
[setup]
script = '''
./.ai-harness setup
'''

[cleanup]
script = '''
./.ai-harness cleanup && ./.ai-harness down
'''
```

To use it, select the `Laravel AI Harness` local environment in Codex when you create a worktree.

The harness overwrites this file on each refresh. Put custom Codex configuration in a different file.

### Codex cloud

The local environment file does not configure Codex cloud. Refer to [Codex cloud](cloud.md#codex-cloud).

## Claude Code

### Instruction files

Claude Code v2.1.277 or later reads `AGENTS.md` when no project `CLAUDE.md` or `CLAUDE.local.md` takes precedence. Thus, the harness does not create `CLAUDE.md`.

When a `CLAUDE.md` exists:

- On `update`, the harness deletes a `CLAUDE.md` that contains only the legacy managed block.
- When `CLAUDE.md` also contains your own text, the harness keeps that text and adds a managed `@AGENTS.md` import.
- `doctor` shows a failure when `CLAUDE.md` does not import `AGENTS.md`.

If you keep other Claude instruction files, import `AGENTS.md` from them, or configure Claude to read both files. Some Claude sessions still need the import, for example sessions on third-party providers or with telemetry disabled. Refer to the [Claude Code instruction-file guidance](https://code.claude.com/docs/en/memory#agentsmd).

### Claude Code hooks

The harness merges package-owned command hooks into `.claude/settings.json`.

| Hook | Added when | Action |
| --- | --- | --- |
| `SessionStart` | `worktrees=true` or `cloud=true` | In a Claude cloud session with `cloud=true`: prepare the project. Locally with `worktrees=true`: prepare the session directory only when it is a Claude worktree under `.claude/worktrees/`. A normal local checkout is not prepared. |
| `PostToolUse` for `EnterWorktree` | `worktrees=true` | Prepare the worktree that Claude reports. |
| `PreToolUse` for `ExitWorktree` | `worktrees=true` | Clean the worktree before Claude removes it. |
| `WorktreeRemove` | `worktrees=true` | Clean `--worktree` and isolated-subagent worktrees before Claude removes them. |
| `SessionEnd` | `cloud=true` | In a cloud session only, remove the owned testing databases. Timeout: 60 seconds. |

Each hook calls `.ai-harness hook claude <event>`. The Composer package reads the JSON payload and does the lifecycle work.

- Existing settings and your own hooks stay.
- When you set `worktrees=false`, the next refresh removes the worktree hooks.
- To remove all harness hooks, set `worktrees=false` and `cloud=false`, then run `./.ai-harness update`.

## Laravel Boost

When [Laravel Boost](https://github.com/laravel/boost) is installed in your application, it can find the short guideline of this package at `resources/boost/guidelines/core.blade.php`. The guideline explains the configuration and the environment boundaries.

The managed block in `AGENTS.md` stays the source for command syntax. AI Harness does not need Boost and does not add Boost files.
