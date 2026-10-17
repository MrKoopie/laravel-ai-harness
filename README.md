# Laravel AI Harness

[![CI](https://github.com/MrKoopie/laravel-ai-harness/actions/workflows/ci.yml/badge.svg)](https://github.com/MrKoopie/laravel-ai-harness/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE.md)

> [!WARNING]
> **0.6.0 has breaking configuration changes. Rename removed keys before upgrading.**
> Replace `herd_php`, `valet_php`, and `yerd_php` with `php_version`; replace `herd_secure`, `valet_secure`, and `yerd_secure` with `site_secure`; remove `services` and `service_manager`; select Sail through `runtime` and `sail_services`.
> Old keys are rejected, including keys in personal files inherited by worktrees. Composer's automatic refresh fails if any remain. Follow the [0.6.0 upgrade instructions](docs/upgrading.md#upgrade-to-060), including the recovery steps if an update has already failed. A `^0.5` Composer constraint does not include `0.6.0`.

Laravel AI Harness gives coding agents (Codex and Claude Code) and people one stable command to run a Laravel project. The command is `./.ai-harness`. It works the same way with native PHP, Laravel Herd, Laravel Valet, Yerd, and Laravel Sail, and in Claude and Codex cloud environments.

```bash
./.ai-harness artisan migrate
./.ai-harness test --filter=ExampleTest
./.ai-harness composer install
```

The harness selects the correct runtime from one configuration file. Agents do not have to know if the project uses `php artisan`, `herd php artisan`, or `sail artisan`.

Personal application settings go in `.ai-harness.config.local`, for example `local_env.DB_PORT=3307` for an existing host database or `local_env.FORWARD_DB_PORT=3307` for Sail-managed MySQL. Run `setup` to apply them. Linked local worktrees inherit these defaults from the primary checkout. See [Local application environment overrides](docs/configuration.md#local-application-environment-overrides).

## Why use it

- **One command for all runtimes.** Agent instructions stay the same when you change from Herd to Sail.
- **Isolated worktrees.** Each checkout gets its own `.env.testing`. With managed Sail MySQL (`mysql` in `sail_services`) or explicit host `db_connection=mysql`, it also gets its own databases. With `runtime=herd`, `runtime=valet`, or `runtime=yerd`, it also gets its own site. Thus, parallel agents do not share data.
- **Safe cleanup.** The harness removes only the resources that it created and recorded.
- **Agent integration.** It writes a shared `AGENTS.md` block, a Codex local environment, and Claude Code hooks.
- **Cloud support.** It provisions and prepares Claude and Codex cloud containers with native PHP, MySQL, and Redis.
- **Small footprint.** The logic stays in the Composer package. Your project gets only a small set of managed files.

## Requirements

- PHP 8.2 or newer.
- Composer 2.2 or newer.
- Bash on macOS, Linux, or WSL.
- A Laravel application.
- Optional: [Laravel Herd](https://herd.laravel.com), [Laravel Valet](https://laravel.com/docs/valet), or [Yerd 2.x](https://yerd.app).
- Optional: [Laravel Sail](https://laravel.com/docs/sail) and Docker.

## Quick start

1. Install the package as a development dependency:

   ```bash
   composer require --dev mrkoopie/laravel-ai-harness
   ```

2. Create the project files:

   ```bash
   ./vendor/bin/ai-harness init
   ```

3. Open `.ai-harness.config` and set your runtime, for example `runtime=herd` or `runtime=valet`. Refer to the [Quick start guide](docs/quick-start.md) for the settings of each runtime.

4. Make sure that the configuration is correct:

   ```bash
   ./.ai-harness doctor
   ```

5. Prepare the checkout (dependencies, `.env`, databases, Herd or Valet site):

   ```bash
   ./.ai-harness setup
   ```

6. Commit the generated files.

From now on, people and agents use `./.ai-harness` for all Laravel, Composer, PHP, and npm commands.

## Global installation

Install once and configure each Laravel project without adding a project dependency:

```bash
composer global require mrkoopie/laravel-ai-harness
# Add the directory printed by this command to your PATH:
composer global config bin-dir --absolute
cd /path/to/your/laravel-project
ai-harness init --global
```

This configures Codex local environments and Claude hooks automatically, using the same options as a project installation. Edit `.ai-harness.config.global` to select your runtime, services, agents, and environment overrides. The harness excludes all generated integrations through Git’s local `.git/info/exclude`; shared `.gitignore`, `composer.json`, and agent configuration stay unchanged. Global initialization requires a Git working tree.

For Codex, generate setup, teardown, and persistent command settings with one command:

```bash
ai-harness init --global --agent=codex \
  --runtime=yerd --php-version=8.5 --database=mysql --migrate --build
```

This only writes configuration. Select **Laravel AI Harness (Codex)** in Codex before creating a worktree. Setup then prepares the application; teardown removes the checkout's owned site, development and testing MySQL databases, and numeric parallel-test databases. Host MySQL uses an existing local service and credentials from `.env` or `.env.example`; the harness does not start or stop that service. See [Codex configuration](docs/configuration.md#codex-environment-example).

All settings have [named init parameters](docs/commands.md#configure-agents-with-parameters), including `--agents=codex,claude`, `--sail-services=mysql,redis`, repeatable `--local-env=NAME=VALUE`, and cloud settings.

Manual exported `AI_HARNESS_*` overrides are still supported. Keep them available to every later harness command; setup does not persist them.

Use `ai-harness doctor`, `ai-harness setup`, or the generated `./.ai-harness` launcher. Linked local worktrees inherit the primary checkout's global configuration. After updating the package with `composer global update mrkoopie/laravel-ai-harness`, run `ai-harness update` in each configured project. See [Global installation details](docs/installation.md#global-installation).

## Commands

| Command | Purpose |
| --- | --- |
| `./.ai-harness artisan ...` | Run Artisan in the configured runtime. |
| `./.ai-harness test ...` | Run `artisan test` in the configured runtime. |
| `./.ai-harness composer ...` | Run Composer in the configured runtime. |
| `./.ai-harness php ...` | Run PHP in the configured runtime. |
| `./.ai-harness npm ...` | Run npm in the configured runtime. |
| `./.ai-harness doctor` | Check the configuration, tools, and managed files. |
| `./.ai-harness setup` | Prepare the current checkout or worktree. |
| `./.ai-harness cleanup` | Remove only the resources that the harness owns. |
| `./.ai-harness up` | Start the configured Sail services. |
| `./.ai-harness down` | Stop the configured Sail services. Volumes stay. |
| `./.ai-harness prune-herd` | Remove Herd sites of deleted worktrees, after you confirm. |
| `./.ai-harness prune-yerd` | Remove Yerd sites of deleted worktrees, after you confirm. |
| `./.ai-harness prune-valet` | Remove Valet sites of deleted worktrees, after you confirm. |
| `./.ai-harness init` | Create the managed project files. |
| `./.ai-harness update` | Refresh the managed project files. |
| `./.ai-harness cloud setup\|maintain\|cleanup` | Prepare or clean a cloud checkout. |

Refer to [Commands](docs/commands.md) for all details.

## Documentation

| Page | Contents |
| --- | --- |
| [Quick start](docs/quick-start.md) | Step-by-step start for native PHP, Herd, Valet, Sail, and cloud users. |
| [Installation](docs/installation.md) | Install, generated files, Composer hooks, and updates. |
| [Configuration](docs/configuration.md) | File format, all keys, and example setups. |
| [Commands](docs/commands.md) | All commands, runtime mapping, and options. |
| [Local environments](docs/local-environments.md) | What `setup`, `cleanup`, `up`, and `down` do with Herd, Valet, Sail, and MySQL. |
| [Coding agents](docs/agents.md) | `AGENTS.md`, Codex local environments, Claude Code hooks, and Laravel Boost. |
| [Cloud environments](docs/cloud.md) | Claude and Codex cloud setup, provisioning, and environment variables. |
| [Private packages](docs/private-packages.md) | Composer credentials for many hosts and authentication types with `COMPOSER_AUTH`. |
| [Upgrading](docs/upgrading.md) | Upgrade from version 0.1. |
| [Troubleshooting](docs/troubleshooting.md) | `doctor` failures and common problems. |
| [Development](docs/development.md) | Work on the package itself. |

## What the harness does not do

- It does not create, change, or select Git branches or worktrees. It never runs `git fetch`, `pull`, `checkout`, `switch`, `branch`, `rebase`, or `worktree`. The agent supplies the current directory. The harness only prepares that directory.
- It does not fall back to a different runtime automatically.
- Local setup runs migrations only when explicitly requested with `setup --migrate`.
- It does not delete Docker volumes.
- It does not copy runtime scripts, skills, or MCP configuration into your project.

## License

Laravel AI Harness is open-source software under the [MIT license](LICENSE.md).
