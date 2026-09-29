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

3. Edit `.ai-harness.config` for your project. Refer to [Configuration](configuration.md).

4. If you changed `agents`, `worktrees`, or `cloud`, refresh the project files:

   ```bash
   ./.ai-harness update
   ```

5. Check the result:

   ```bash
   ./.ai-harness doctor
   ```

6. Commit the generated files.

## Managed project files

The harness manages only these files:

| File | When | Contents |
| --- | --- | --- |
| `.ai-harness` | Always | Bootstrap script for all commands. |
| `.ai-harness.config` | When no configuration file exists | Default project configuration. |
| `.gitignore` | Always | One managed block. |
| `composer.json` | Always | Two guarded script entries. |
| `.ai-harness-cloud` | `cloud=true` | Cloud provisioning entrypoint. |
| `AGENTS.md` | `codex` or `claude` in `agents` | One managed block. |
| `.codex/environments/environment.toml` | `codex` in `agents` and `worktrees=true` | Codex local environment. |
| `.claude/settings.json` | `claude` in `agents` and `worktrees=true` or `cloud=true` | Package-owned hooks only. |
| `CLAUDE.md` | Only when it already exists | Removes the legacy managed block. With `claude` in `agents`, adds a managed `@AGENTS.md` import. Deletes the file when only the legacy block was in it. Refer to [Coding agents](agents.md#instruction-files). |

When you disable an option, the next refresh removes the related file or block. The harness removes a file only when its contents are still the unchanged package version.

The harness does not copy runtime executors, database scripts, skills, MCP configuration, or per-agent shell scripts into your project.

The managed `.gitignore` block ignores these files:

- `.ai-harness.config.local`
- `.ai-harness.state.json`
- `.env.testing`
- All of `.codex/` except `.codex/environments/environment.toml`

Content outside the managed blocks stays unchanged. The harness overwrites `.codex/environments/environment.toml` on each refresh. Put custom Codex configuration in a different file.

## The bootstrap script

`.ai-harness` is a small Bash script. It sends each command to `vendor/bin/ai-harness`.

When `vendor/bin/ai-harness` is missing, the script first installs the dependencies:

- It runs `composer install --no-interaction --prefer-dist`.
- When Composer is not on `PATH`, it uses `herd composer install` instead.

The bootstrap never adds or updates package requirements. When `composer.lock` exists, it installs the locked versions. When `composer.lock` does not exist, Composer resolves the versions from `composer.json` and creates a new lock file. Commit `composer.lock` to get the same versions everywhere. In cloud mode, the bootstrap stops when `composer.lock` is missing.

## Automatic refresh with Composer

`init` adds one guarded command to the `post-install-cmd` and `post-update-cmd` events in your root `composer.json`. After each `composer install` or `composer update`, this command refreshes the managed files.

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
- Link Herd or Valet sites.
- Run migrations.
- Read or change Git.

Use `setup`, `cleanup`, `up`, `down`, and the cloud commands for environment work. Refer to [Local environments](local-environments.md) and [Cloud environments](cloud.md).
