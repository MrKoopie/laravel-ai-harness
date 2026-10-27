# Coding agents

The harness integrates with Codex and Claude Code. Select the agents with the `agents` key, `init --agents=codex,claude`, or repeated `--agent=codex --agent=claude` parameters. Explicit selections and other init parameters are preserved in each selected agent's command environment. Refer to [Configuration](configuration.md#agents).

## Personal integrations in global mode

`ai-harness init --global` installs integrations for you in the current Git working tree, keeping generated files out of commits through `.git/info/exclude`. Shared instructions, settings, and `.gitignore` are left unchanged.

- Codex uses `AGENTS.override.md` and `.codex/environments/ai-harness-global.toml`. Select `Laravel AI Harness (global)` after basic init, or `Laravel AI Harness (Codex)` after parameter-based init. Codex loads the override instead of `AGENTS.md`, so the harness includes shared guidance in its managed block and refreshes that snapshot on `update`. Your instructions outside the block remain intact. See [Codex instruction discovery](https://learn.chatgpt.com/docs/agent-configuration/agents-md).
- Claude uses `CLAUDE.local.md` and `.claude/settings.local.json`. Its personal instructions import existing `AGENTS.md`; existing `CLAUDE.md` is untouched. Hooks and commands use `ai-harness` on `PATH`. Your other personal settings, hooks, and instructions remain intact. See [Claude settings scopes](https://code.claude.com/docs/en/settings) and [personal instructions](https://code.claude.com/docs/en/memory).

Ignored integrations are not copied by Git into new worktrees. Codex's global environment setup and Claude's global worktree hooks initialize them before runtime setup. Git exclusions are shared by linked worktrees through the common Git directory. Turning off an agent removes only its personal harness-managed content on the next update.

The remaining sections describe project installations, whose integration files can be committed.

## Shared instructions in `AGENTS.md`

When `codex` or `claude` is in `agents`, `init` and `update` add one managed block to `AGENTS.md`. The block tells agents to:

- Run Laravel, test, Composer, PHP, and npm commands through `./.ai-harness`.
- Use `up`, `down`, and `doctor` for the local environment.
- Not bypass the configured runtime unless the user asks.
- Run Herd, Valet, and Yerd commands outside the Codex sandbox.
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

The basic environment above is refreshed on update. For runtime selections, migrations, builds, and settings for later commands, use `init --agent=codex`; see the [complete Codex configuration example](configuration.md#codex-environment-example). Its configured environment is preserved on ordinary refreshes and includes ownership-aware database cleanup.

### Codex cloud

The local environment file does not configure Codex cloud or its hosted setup/maintenance scripts. Export the corresponding `AI_HARNESS_CLOUD_*` values in that environment when using process-based cloud settings. Refer to [Codex cloud](cloud.md#codex-cloud).

## Claude Code

### Parameter-selected command settings

`init --agents=claude` and `init --agents=codex,claude` merge all resolved harness selections into Claude's `env` object. The file is `.claude/settings.local.json` in global mode and `.claude/settings.json` in shared mode. Other environment variables, permissions, and custom hooks remain. Command settings are written even with `--worktrees=false --cloud=false`, when no lifecycle hooks are enabled.

Start a new agent session to use newly written values. Hook-initialized worktrees retain inherited harness selections. Normal terminal commands do not automatically read Claude's settings; see [where selections apply](configuration.md#where-parameter-selections-apply). Local `--migrate` and `--build` flags configure Codex worktree setup; use direct `setup --migrate --build` in a configured Claude session when those local steps are needed.

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

## Deselecting agents

Explicit `init --agents=codex`, `--agents=claude`, or `--agents=` removes generated environment values for deselected agents, along with their owned lifecycle integration. Unrelated environment variables, permissions, and custom Codex environments remain. Ordinary `update` does not copy process settings into shared Claude configuration; use `init` to generate new shared agent environment settings.

## Laravel Boost

When [Laravel Boost](https://github.com/laravel/boost) is installed in your application, it can find the short guideline of this package at `resources/boost/guidelines/core.blade.php`. The guideline explains the configuration and the environment boundaries.

The managed block in `AGENTS.md` stays the source for command syntax. AI Harness does not need Boost and does not add Boost files.
