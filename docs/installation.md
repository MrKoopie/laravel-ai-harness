# Installation

This page tells you how to install the harness, which files it adds to your project, and how to update it.

## Install the package

1. Add the package as a development dependency:

   ```bash
   composer require --dev mrkoopie/laravel-ai-harness
   ```

2. Create the project files:

   ```bash
   ./vendor/bin/ai-harness init
   ```

3. Edit `.ai-harness.config` for file-based defaults, or initialize with [named parameters](commands.md#configure-agents-with-parameters), for example `./vendor/bin/ai-harness init --agents=codex,claude --runtime=native`. Parameter selections go into agent settings and do not replace the harness file defaults.

4. If you changed `agents`, `worktrees`, or `cloud`, refresh the project files:

   ```bash
   ./.ai-harness update
   ```

5. Check the result:

   ```bash
   ./.ai-harness doctor
   ```

6. Review the generated files, then commit the intended shared files. Parameter-generated agent files include effective local override values; use global mode for personal values and credentials.

## Global installation

Use the global Composer installation when the harness should be available across projects without adding it to their dependencies:

```bash
composer global require mrkoopie/laravel-ai-harness
composer global config bin-dir --absolute
```

Add the bin directory printed by the second command to your shell's `PATH`. Ensure that Codex and Claude also inherit that `PATH`, then run this from each Laravel project:

```bash
ai-harness init --global
```

For parameter-based setup, use the same global command with your selections:

```bash
ai-harness init --global --agents=codex,claude \
  --runtime=yerd --php-version=8.5 --database=mysql --migrate --build
```

Use a package version that includes these options (`ai-harness init --help` lists them). A repository change does not update an existing Composer-global installation; install the updated package before using new options. `init --global` alone continues to generate the basic integration.

You can also target another directory with `ai-harness init --global --path /path/to/project`.

Global initialization requires Git 2.31 or newer and an existing Git working tree. It creates personal integrations without changing shared `.gitignore`, `composer.json`, `AGENTS.md`, `CLAUDE.md`, or `.claude/settings.json`:

| Personal file | Purpose |
| --- | --- |
| `.ai-harness.config.global` | All harness configuration keys and validation rules. |
| `AGENTS.override.md` | Codex instructions, including a snapshot of existing shared `AGENTS.md` guidance. |
| `CLAUDE.local.md` | Claude instructions, importing existing shared `AGENTS.md` when present. |
| `.claude/settings.local.json` | Claude lifecycle hooks and parameter-selected command environment, preserving unrelated personal settings. |
| `.codex/environments/ai-harness-global.toml` | `Laravel AI Harness (global)` for basic init, or `Laravel AI Harness (Codex)` for parameter-based setup with worktrees enabled. |
| `.codex/config.toml` | For parameter-selected Codex: merged `[shell_environment_policy.set]` values for later commands. |
| `.ai-harness`, `.ai-harness-cloud` | Convenience launchers, created only when those paths are not already tracked. |

All these files, personal local overrides, harness state, and `.env.testing` are excluded through the repository's local `.git/info/exclude`. The harness preserves existing exclude rules and maintains one managed block. Linked worktrees use the common Git directory's `info/exclude`. Nested applications get patterns scoped to their project directory. Git exclusions do not hide changes to tracked files: tracked personal integration paths cause initialization to stop before generating files; tracked shared launchers are preserved. The harness never removes files from Git's index or marks them `skip-worktree`.

Use `ai-harness` from `PATH` for commands. Claude's personal hooks also call this command directly. The generated launchers are optional conveniences. Application dependencies and optional Sail still belong to the application; `setup` installs those as usual.

Existing configuration is preserved: when shared or local settings already exist, the new global configuration starts with comments so it does not override them. Otherwise, it contains the default configuration. Global settings override shared settings, and `.ai-harness.config.local` overrides global settings. See [Configuration](configuration.md#configuration-files).

`init` and `update` remember global mode from `.ai-harness.config.global`. Linked local worktrees inherit it from the primary checkout. In Codex, select `Laravel AI Harness (global)` for basic initialization or `Laravel AI Harness (Codex)` for parameter-based setup; its setup command initializes ignored integrations in the new worktree before preparing the runtime. Claude's worktree hooks do the same. Each independent clone or cloud container needs its own global package installation and initialization, with Composer's global bin directory on `PATH`.

Omitting `--global` does not switch an initialized global project to shared mode: the active `.ai-harness.config.global` marker, including an inherited primary marker, still selects global mode.

When upgrading from the earlier global implementation, shared files it previously created remain unchanged. Exclusions cannot hide already-tracked files, and the harness does not automatically remove old shared instructions or hooks. Remove obsolete harness hooks from shared Claude settings before enabling personal hooks to avoid running both copies.

Refresh a global installation with:

```bash
composer global update mrkoopie/laravel-ai-harness
ai-harness update --path /path/to/project
```

Run the second command for each configured project. Global installs have no automatic project Composer refresh hooks; `doctor` recognizes this mode. Existing Composer hooks from a previous project installation are preserved but are not required for global mode.

## Managed project files

Project installations manage these files. Global installations use the personal paths listed above:

| File | When | Contents |
| --- | --- | --- |
| `.ai-harness` | Always | Bootstrap script for all commands. |
| `.ai-harness.config` | When no configuration file exists | Default project configuration. |
| `.gitignore` | Always | One managed block. |
| `composer.json` | Project installation only | Two guarded script entries. |
| `.ai-harness-cloud` | `cloud=true` | Cloud provisioning entrypoint. |
| `AGENTS.md` | `codex` or `claude` in `agents` | One managed block. |
| `.codex/environments/environment.toml` | `codex` in `agents` and `worktrees=true` | Codex local environment. |
| `.claude/settings.json` | Claude hooks enabled, or selected Claude parameters/process overrides | Package-owned hooks and, when supplied, a merged command environment. |
| `.codex/config.toml` | Parameters supplied with Codex selected | Merged harness command variables. Ignored by the default project `.gitignore` block. |
| `CLAUDE.md` | Only when it already exists | Removes the legacy managed block. With `claude` in `agents`, adds a managed `@AGENTS.md` import. Deletes the file when only the legacy block was in it. Refer to [Coding agents](agents.md#instruction-files). |

When you disable an option, the next refresh removes the related file or block. The harness removes a file only when its contents are still the unchanged package version.

The harness does not copy runtime executors, database scripts, skills, MCP configuration, or per-agent shell scripts into your project.

The managed `.gitignore` block ignores these files:

- `.ai-harness.config.local`
- `.ai-harness.state.json`
- `.env.testing`
- All of `.codex/` except `.codex/environments/environment.toml`

Content outside managed blocks stays unchanged. Basic Codex environment templates are refreshed on `init` and `update`. Parameter-generated Codex environments are preserved by ordinary refreshes; rerun `init` with the desired parameters to regenerate them. Explicit `init --agents=codex --worktrees=false` removes a parameter-generated worktree environment and retains command settings.

The generated environment scripts and Claude settings contain the full effective selections, including inherited `local_env` values. Shared mode can put these values in files that are committed. Use global mode for personal settings or credentials. Global mode rejects tracked personal paths, including `.codex/config.toml` when generating Codex parameters, rather than changing tracked shared configuration. See [parameter selection scope](configuration.md#where-parameter-selections-apply).

## The bootstrap script

`.ai-harness` is a small Bash script. In a project installation it sends each command to `vendor/bin/ai-harness`. In a global installation it calls `ai-harness` on `PATH`, reporting how to restore it if missing, without installing a project dependency.

When `vendor/bin/ai-harness` is missing, the script first installs the dependencies:

- It runs `composer install --no-interaction --prefer-dist`.
- When Composer is not on `PATH`, it uses `herd composer install` instead.

The bootstrap never adds or updates package requirements. When `composer.lock` exists, it installs the locked versions. When `composer.lock` does not exist, Composer resolves the versions from `composer.json` and creates a new lock file. Commit `composer.lock` to get the same versions everywhere. In cloud mode, the bootstrap stops when `composer.lock` is missing.

## Automatic refresh with Composer

For project installations, `init` adds one guarded command to the `post-install-cmd` and `post-update-cmd` events in your root `composer.json`. After each `composer install` or `composer update`, this command refreshes the managed files.

- Existing project scripts stay.
- A second `init` does not add duplicates.
- The hook does nothing when Composer runs with `--no-dev`.
- The hook does nothing when `vendor/bin/ai-harness` does not exist.

## Update the package

To install a newer release and refresh the files:

```bash
composer update mrkoopie/laravel-ai-harness
```

These are two different operations:

1. `composer update mrkoopie/laravel-ai-harness` downloads a newer package release.
2. `./.ai-harness update` refreshes the project files from the release that is installed now.

The Composer hook runs step 2 automatically after step 1.

### Update without Composer scripts

For recovery or debugging, disable the Composer scripts and refresh manually:

```bash
composer update mrkoopie/laravel-ai-harness --no-scripts
./vendor/bin/ai-harness update
```

To upgrade from version 0.1, refer to [Upgrading](upgrading.md).

## `init` and `update` scope

`init` and `update` only write the managed project files. They do not:

- Install dependencies.
- Start containers.
- Create databases.
- Link Herd, Valet, or Yerd sites.
- Run migrations.
- Change Git branches, worktrees, or the index. Global installations query repository metadata and maintain local `info/exclude` rules.

Use `setup`, `cleanup`, `up`, `down`, and the cloud commands for environment work. Refer to [Local environments](local-environments.md) and [Cloud environments](cloud.md).
