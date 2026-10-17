# Quick start

Use the parameter-based agent setup below, or follow the common steps for a file-based project installation and then the section for your runtime.

| You use | Go to |
| --- | --- |
| Configure Codex, Claude, or both with parameters | [Agent setup](#agent-setup-with-parameters) |
| PHP that you installed yourself, with SQLite | [Native PHP](#native-php) |
| Laravel Herd | [Laravel Herd](#laravel-herd) |
| Yerd 2.x | [Yerd](#yerd) |
| Laravel Valet | [Laravel Valet](#laravel-valet) |
| Laravel Sail for everything | [Laravel Sail](#laravel-sail) |
| Herd, Valet, Yerd, or native PHP, with MySQL from Sail | [MySQL from Sail](#mysql-from-sail) |
| Claude Code or Codex in the cloud | [Cloud](#cloud) |
| A different runtime than your team | [A different runtime than your team](#a-different-runtime-than-your-team) |

## Agent setup with parameters

Install or update the global package once:

```bash
composer global require mrkoopie/laravel-ai-harness
composer global config bin-dir --absolute
```

Put that bin directory on the agent's `PATH`. From a Laravel project in a Git working tree, generate the settings:

```bash
ai-harness init --global --agents=codex,claude \
  --runtime=yerd --php-version=8.5 --site-secure=true \
  --database=mysql --migrate --build
```

This writes configuration only. Yerd and the requested PHP version must already be installed, and host MySQL or MariaDB must already be running. The database account from `.env` or `.env.example` needs CREATE/DROP permissions; see [host MySQL prerequisites](local-environments.md#existing-host-mysql-or-mariadb). Omit `--database=mysql` to retain the existing database defaults.

In Codex, select **Laravel AI Harness (Codex)** before creating a worktree. Its setup initializes the new checkout, prepares the site and isolated databases, runs development migrations, and builds locked frontend dependencies. Restart or open the selected agent session to load newly generated command settings. Generating a file does not prove that the app has selected it.

For Claude only, use `--agents=claude` and omit the Codex-specific `--migrate` and `--build` flags. For MySQL and Redis from Sail, add `--sail-services=mysql,redis`. Every setting has a [named parameter](commands.md#configure-agents-with-parameters).

From a configured agent session, run `ai-harness doctor` to inspect the effective values and sources. Commands in an ordinary terminal do not automatically read agent settings; export the same variables there or use file-based personal defaults. See [where selections apply](configuration.md#where-parameter-selections-apply).

Before deleting a prepared local checkout, run:

```bash
ai-harness cleanup && ai-harness down
```

The generated Codex teardown runs this sequence automatically when invoked by the app. Cleanup removes owned development, testing, and numeric parallel-test databases before stopping Sail; a failure leaves ownership available for retry. Host database services remain running. See [cleanup and retries](local-environments.md#cleanup-and-retries).

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

6. Review and commit the intended shared files and `composer.lock`. Keep personal values out of shared agent files; use global mode when selections include credentials.

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
```

`setup` creates `.env`, `APP_KEY`, and a `.env.testing` file with SQLite.

## Laravel Herd

1. Install [Laravel Herd](https://herd.laravel.com) and make sure that `herd` is on your `PATH`.
2. Set the runtime:

   ```ini
   runtime=herd
   site_secure=true
   php_version=8.4
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
   site_secure=true
   php_version=8.4
   ```

4. Run `./.ai-harness setup`. Each worktree gets its own site, for example `https://my-app-1a2b3c4d5e.test`. When you changed the Valet TLD, the harness uses your TLD.

When `php_version` has a value and that PHP version is not installed, Valet installs it with Homebrew. This can take some minutes.

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
   sail_services=
   ```

4. Run `./.ai-harness setup`. It starts the Sail containers.

Use `./.ai-harness up` and `./.ai-harness down` to start and stop the containers. The harness never deletes Docker volumes.

## MySQL from Sail

You can run PHP with Herd, Valet, Yerd, or native PHP, and get MySQL and Redis from Sail. Add these keys to the runtime keys of your section:

```ini
sail_services=mysql,redis
```

`setup` creates a development database and a testing database for each worktree. `cleanup` drops them. When another MySQL server already uses port 3306, put `local_env.FORWARD_DB_PORT=3307` in `.ai-harness.config.local` and run `setup`.

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
php_version=8.4
```

The shared `php_version` and `site_secure` settings also apply to Valet. Override them here only when your personal setup needs different values.

Then run `./.ai-harness setup` again.

The same file can override application environment settings, such as `local_env.DB_PORT=3307` for an existing host database or `local_env.FORWARD_DB_PORT=3307` for Sail-managed MySQL. Linked local worktrees inherit your primary checkout's local file, and can override individual values in their own local file. See [Local application environment overrides](configuration.md#local-application-environment-overrides).

## Coding agents

`init` also writes the instructions for Codex and Claude Code. Agents then use `./.ai-harness` for all commands. In Codex, Herd and Valet commands must run outside the sandbox. The managed `AGENTS.md` block tells Codex to ask for this. Refer to [Coding agents](agents.md).

## When something fails

Run `./.ai-harness doctor`. For each `FAIL` line, refer to [Troubleshooting](troubleshooting.md).

## Yerd

Install [Yerd 2.x](https://yerd.app/guide/getting-started), start its daemon, and install the PHP version you need and Composer. Put Yerd's bin directory on PATH with `yerd path install`.

```ini
runtime=yerd
site_secure=true
php_version=8.4
```

Run `./.ai-harness setup`, then `./.ai-harness doctor`. The harness creates a checkout-specific linked site, configures Laravel's `public` web root and front controller, and pins only this site's PHP. PHP, Artisan, tests, and Composer use the versioned Yerd PHP shim. Existing sites and global PHP are preserved.

Use `./.ai-harness cleanup` before deleting the worktree, or inspect stale generated links with `./.ai-harness prune-yerd --dry-run`. See [Yerd](local-environments.md#yerd) for ownership and Composer details.
