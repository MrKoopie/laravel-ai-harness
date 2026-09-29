# Quick start

This page tells you how to start with the harness for your type of setup. Do the common steps first. Then do the steps for your runtime.

| You use | Go to |
| --- | --- |
| PHP that you installed yourself, with SQLite | [Native PHP](#native-php) |
| Laravel Herd | [Laravel Herd](#laravel-herd) |
| Laravel Valet | [Laravel Valet](#laravel-valet) |
| Laravel Sail for everything | [Laravel Sail](#laravel-sail) |
| Herd, Valet, or native PHP, with MySQL from Sail | [MySQL from Sail](#mysql-from-sail) |
| Claude Code or Codex in the cloud | [Cloud](#cloud) |
| A different runtime than your team | [A different runtime than your team](#a-different-runtime-than-your-team) |

## Common steps

Do these steps one time for each project:

1. Install the package:

   ```bash
   composer require --dev mrkoopie/laravel-ai-harness
   ```

2. Create the project files:

   ```bash
   ./vendor/bin/ai-harness init
   ```

3. Set the runtime in `.ai-harness.config`. Refer to the section for your runtime below.

4. Check the configuration:

   ```bash
   ./.ai-harness doctor
   ```

5. Prepare the checkout:

   ```bash
   ./.ai-harness setup
   ```

6. Commit the generated files and `composer.lock`.

After that, use `./.ai-harness` for all Laravel, Composer, PHP, and npm commands:

```bash
./.ai-harness artisan migrate
./.ai-harness test
./.ai-harness composer require vendor/package
```

Run `./.ai-harness setup` again in each new worktree. Run `./.ai-harness cleanup` before you delete a worktree.

## Native PHP

Use this when PHP 8.2 or newer and Composer are on your `PATH`.

```ini
runtime=native
services=none
```

`setup` creates `.env`, `APP_KEY`, and a `.env.testing` file with SQLite.

## Laravel Herd

1. Install [Laravel Herd](https://herd.laravel.com) and make sure that `herd` is on your `PATH`.
2. Set the runtime:

   ```ini
   runtime=herd
   herd_secure=true
   herd_php=8.4
   ```

3. Run `./.ai-harness setup`. Each worktree gets its own site, for example `https://my-app-1a2b3c4d5e.test`.

To remove the sites of worktrees that you deleted without `cleanup`:

```bash
./.ai-harness prune-herd --dry-run
./.ai-harness prune-herd
```

Refer to [Laravel Herd](local-environments.md#laravel-herd).

## Laravel Valet

Valet runs only on macOS.

1. Install [Laravel Valet](https://laravel.com/docs/valet) and make sure that `valet` is on your `PATH`.
2. Run this command one time:

   ```bash
   valet trust
   ```

   Without it, Valet asks for a `sudo` password on `link`, `secure`, and `isolate`. An agent cannot type a password.

3. Set the runtime:

   ```ini
   runtime=valet
   valet_secure=true
   valet_php=8.4
   ```

4. Run `./.ai-harness setup`. Each worktree gets its own site, for example `https://my-app-1a2b3c4d5e.test`. When you changed the Valet TLD, the harness uses your TLD.

When `valet_php` has a value and that PHP version is not installed, Valet installs it with Homebrew. This can take some minutes.

To remove the sites of worktrees that you deleted without `cleanup`:

```bash
./.ai-harness prune-valet --dry-run
./.ai-harness prune-valet
```

Refer to [Laravel Valet](local-environments.md#laravel-valet).

## Laravel Sail

1. Install Docker.
2. Install Sail with the Composer on your computer:

   ```bash
   composer require laravel/sail --dev
   ```

3. Set the runtime:

   ```ini
   runtime=sail
   services=sail
   sail_services=
   ```

4. Run `./.ai-harness setup`. It starts the Sail containers.

Use `./.ai-harness up` and `./.ai-harness down` to start and stop the containers. The harness never deletes Docker volumes.

## MySQL from Sail

You can run PHP with Herd, Valet, or native PHP, and get MySQL and Redis from Sail. Add these keys to the runtime keys of your section:

```ini
services=sail
sail_services=mysql,redis
```

`setup` creates a development database and a testing database for each worktree. `cleanup` drops them. When another MySQL server already uses port 3306, set `FORWARD_DB_PORT=3307` in `.env`.

Refer to [MySQL with Sail](local-environments.md#mysql-with-sail).

## Cloud

In Claude Code or Codex cloud environments, the harness always uses native PHP and ignores the local runtime.

1. Keep `cloud=true` in `.ai-harness.config`. This is the default.
2. Commit `.ai-harness`, `.ai-harness-cloud`, `AGENTS.md`, `.claude/settings.json`, and `composer.lock`.
3. Do the steps for your cloud in [Cloud environments](cloud.md).

## A different runtime than your team

The team puts the shared settings in `.ai-harness.config`. You can override them in `.ai-harness.config.local`. Git ignores this file.

Example: the team uses Herd, but you use Valet. Put this in `.ai-harness.config.local`:

```ini
runtime=valet
valet_php=8.4
```

`herd_php` does not apply to Valet. Set `valet_php` yourself when you need a specific PHP version.

Then run `./.ai-harness setup` again.

## Coding agents

`init` also writes the instructions for Codex and Claude Code. Agents then use `./.ai-harness` for all commands. In Codex, Herd and Valet commands must run outside the sandbox. The managed `AGENTS.md` block tells Codex to ask for this. Refer to [Coding agents](agents.md).

## When something fails

Run `./.ai-harness doctor`. For each `FAIL` line, refer to [Troubleshooting](troubleshooting.md).
