# Commands

Run all commands from the project root through the `./.ai-harness` bootstrap script:

```bash
./.ai-harness <command> [arguments]
```

`./vendor/bin/ai-harness` accepts the same commands. Use it only when `.ai-harness` does not exist yet, for example before `init`.

With a [global installation](installation.md#global-installation), `ai-harness` on `PATH` accepts the same commands. Run `ai-harness init --global` once per project to create the agent integrations and an ignored `.ai-harness.config.global`. Subsequent `init` and `update` commands retain global mode, including in linked local worktrees.

## Configure agents with parameters

Every harness configuration key is available on `init`. For example:

```bash
ai-harness init --global --agents=codex,claude \
  --runtime=yerd --php-version=8.5 --site-secure=true \
  --sail-services=mysql,redis \
  --local-env=FORWARD_DB_PORT=3308 \
  --local-env=CUSTOM_FEATURE_ENABLED=true \
  --cloud=true --cloud-services=mysql,redis \
  --cloud-migrate=true --cloud-seed=false --cloud-build=true --cloud-browser=false
```

This generates configuration without provisioning resources. Parameters override process variables and inherited files; omitted parameters keep their effective values. Agent selections are saved in the selected agents' configuration, rather than copied into harness config files or Laravel `.env`. A new session in that agent receives the settings. Select **Laravel AI Harness (Codex)** in the app for Codex worktree automation. `--agents=claude` works without Codex files.

| Configuration key | Parameter |
| --- | --- |
| `runtime` | `--runtime=native\|herd\|valet\|yerd\|sail` |
| `sail_services` | `--sail-services=mysql,redis` (empty: full stack for Sail PHP, no containers for host PHP) |
| `agents` | `--agents=codex,claude` or repeated `--agent=codex --agent=claude` |
| `site_secure` | `--site-secure=true\|false` |
| `php_version` | `--php-version=8.5` (empty preserves the site's selection) |
| `database_provision_username` | `--database-provision-username=local_admin` |
| `database_provision_password` | `--database-provision-password=...` |
| `database_parallel_processes` | `--database-parallel-processes=4` (0–64) |
| `db_connection` | `--db-connection=auto\|sqlite\|mysql\|mariadb` or `--database=...` |
| `worktrees` | `--worktrees=true\|false` |
| `cloud` | `--cloud=true\|false` |
| `cloud_services` | `--cloud-services=mysql,redis` (empty selects SQLite) |
| `cloud_migrate` | `--cloud-migrate=true\|false` |
| `cloud_seed` | `--cloud-seed=true\|false` (requires cloud migrations) |
| `cloud_build` | `--cloud-build=true\|false` |
| `cloud_browser` | `--cloud-browser=true\|false` |
| `local_env.<VARIABLE>` | Repeat `--local-env=NAME=VALUE`; `--local-env=NAME=` explicitly clears it |

Boolean settings take an explicit value. Empty lists use `--sail-services=` or `--cloud-services=`. Empty `--agents=` disables the current integrations and writes no agent environment. Use either `--agents` or repeated `--agent` flags; don't combine them. `--database` and `--db-connection` are aliases and cannot be combined. Duplicate local variables fail before files are generated. Local values are literal data, split at the first `=`; quote the entire argument when the shell might expand its value.

`--migrate` and `--build` remain separate opt-ins for **local** Codex worktree setup, requiring Codex with `worktrees=true`. Omit these flags when regenerating to disable them. They are independent of `--cloud-migrate=true` and `--cloud-build=true`, which affect cloud setup. `setup --migrate --build` can also run the local steps directly; plain `setup` does neither.

With `--worktrees=false`, Codex still receives command settings but the generated worktree environment is removed. Claude receives command settings even when worktree and cloud hooks are disabled. See [agent configuration](configuration.md#codex-environment-example) and [host MySQL](local-environments.md#existing-host-mysql-or-mariadb).

## Runtime commands

These commands run a tool in the configured runtime. Refer to [Runtimes](configuration.md#runtimes) for the exact command that each runtime uses.

| Command | Runs |
| --- | --- |
| `./.ai-harness artisan <args>` | Artisan |
| `./.ai-harness test <args>` | `artisan test` |
| `./.ai-harness composer <args>` | Composer |
| `./.ai-harness php <args>` | PHP |
| `./.ai-harness npm <args>` | npm |

Examples:

```bash
./.ai-harness artisan migrate
./.ai-harness artisan make:model Post --migration
./.ai-harness test --filter=ExampleTest
./.ai-harness test --parallel
./.ai-harness composer require laravel/sanctum
./.ai-harness php -v
./.ai-harness npm run build
```

The harness sends the arguments as an argument array. It does not use a shell command string. Options, spaces, and shell metacharacters go to the tool without change.

Runtime commands always operate in the current directory.

## Project file commands

| Command | Description |
| --- | --- |
| `init` | Create `.ai-harness.config` when no configuration file exists. Then write all managed project files. |
| `update` | Write all managed project files again from the installed package release. Like `init`, it creates `.ai-harness.config` only when no configuration file exists. |
| `doctor` | Check the configuration, the runtime tools, and the managed files. Returns a non-zero exit code when a check fails. |

`init` and `update` do not install dependencies, start containers, create databases, or select/change Git branches or worktrees. Global initialization maintains local Git exclusion rules. Refer to [Installation](installation.md#init-and-update-scope).

### `doctor` output

`doctor` shows the detected environment, runtime, services, and agents. Then it shows one line for each check:

```text
Environment: local
Runtime: herd; services: sail; agents: codex, claude
OK .ai-harness bootstrap is executable
OK Laravel artisan entrypoint exists
OK Configuration loaded from .ai-harness.config
OK Automatic Composer refresh hooks are installed
OK Laravel Herd is available
OK Inferred Sail services are available
OK COMPOSER_AUTH is valid for http-basic (repo.example.com); github-oauth (github.com)
OK Agent instructions are installed
OK Codex local environment is installed
OK Claude hooks are installed
```

The `COMPOSER_AUTH` line shows only when the variable is set. Refer to [Private packages](private-packages.md).

For each `FAIL` line, refer to [Troubleshooting](troubleshooting.md).

## Environment commands

| Command | Local behavior | Cloud behavior |
| --- | --- | --- |
| `setup` | Prepare the current checkout. | Same as `cloud setup`. |
| `cleanup` | Remove only resources that the harness owns. | Same as `cloud cleanup`. |
| `up` | Start the configured Sail services. | Same as `cloud setup`. |
| `down` | Stop the configured Sail services. Volumes stay. | Do nothing. Services stay available for resumed sessions. |

Refer to [Local environments](local-environments.md) for the details of each step.

## Herd and Valet maintenance

| Command | Description |
| --- | --- |
| `./.ai-harness prune-herd` | Find Herd sites whose checkout no longer exists, and remove them after you confirm. |
| `./.ai-harness prune-yerd` | Inspect generated Yerd links with missing targets; confirm each removal. |
| `./.ai-harness prune-valet` | Find Valet sites whose checkout no longer exists, and remove them after you confirm. |

Refer to [Remove orphaned Herd sites](local-environments.md#remove-orphaned-herd-sites) and [Remove orphaned Valet sites](local-environments.md#remove-orphaned-valet-sites).

## Cloud commands

| Command | Description |
| --- | --- |
| `./.ai-harness cloud setup` | Prepare a cloud checkout. |
| `./.ai-harness cloud maintain` | Prepare a checkout again after the provider restores a cache. |
| `./.ai-harness cloud cleanup` | Remove only the owned testing databases. Development data stays. |

The `.ai-harness-cloud` script accepts `provision`, `setup`, `maintain`, and `cleanup`. `provision` installs system packages. The other actions call the cloud commands above. Refer to [Cloud environments](cloud.md).

## Hook command

```bash
./.ai-harness hook claude <event>
```

Claude Code calls this command from the hooks in `.claude/settings.json`. You do not run it yourself. Refer to [Coding agents](agents.md#claude-code-hooks).

## The `--path` option

`init`, `update`, `doctor`, `setup`, `cleanup`, `up`, `down`, `prune-herd`, `prune-valet`, `prune-yerd`, and `cloud` accept `--path=/path/to/project`. Use it to operate on a different project directory:

```bash
./vendor/bin/ai-harness doctor --path=/path/to/project
```

The default is the current directory.
