# Troubleshooting

Start with `doctor`:

```bash
./.ai-harness doctor
```

Each `FAIL` line tells you what is wrong. This page gives the fix for each message.

## `doctor` failures

| Message | Fix |
| --- | --- |
| `.ai-harness bootstrap is missing or not executable` | Run `./vendor/bin/ai-harness update`. Make sure that Git keeps the executable bit (`git update-index --chmod=+x .ai-harness`). |
| `Laravel artisan entrypoint is missing` | Run the command in the root of a Laravel application, or use `--path`. |
| `No project configuration file exists; run init` | Run `./vendor/bin/ai-harness init`. |
| `Automatic Composer refresh hooks are missing; run ./.ai-harness update` | Run `./.ai-harness update`. |
| `Native PHP is unavailable` | Install PHP 8.2 or newer and add it to `PATH`, or change `runtime`. |
| `Laravel Herd is unavailable` | Install Herd and make sure that `herd` is on `PATH`, or change `runtime`. |
| `Laravel Valet is unavailable` | Install Valet and make sure that `valet` is on `PATH`, or change `runtime`. |
| `Laravel Sail is missing or not executable` | Install Sail with the host Composer: `composer require laravel/sail --dev`. Do not use `./.ai-harness composer`, because with `runtime=sail` it needs Sail. Or change `runtime`. |
| `services=sail requires vendor/bin/sail` | Install Laravel Sail with `./.ai-harness composer require laravel/sail --dev`, or set `services=none`. |
| `Cloud mysql is missing; run cloud provision` | Run `./.ai-harness-cloud provision`. |
| `Cloud redis-cli is missing; run cloud provision` | Run `./.ai-harness-cloud provision`. |
| `Agent instructions are missing` | Run `./.ai-harness update`. |
| `Codex local environment is missing` | Run `./.ai-harness update`. |
| `Claude instructions in CLAUDE.md shadow AGENTS.md` | Add the line `@AGENTS.md` to `CLAUDE.md`, or configure Claude to read both files. |
| `Claude hooks are missing` | Run `./.ai-harness update`. |
| `COMPOSER_AUTH is not valid: ...` | Correct the JSON in `COMPOSER_AUTH`. The message names the type and host. Refer to [Private packages](private-packages.md). |
| `COMPOSER_AUTH is set but the Sail compose file does not forward it` | Add `COMPOSER_AUTH: '${COMPOSER_AUTH:-}'` to the `laravel.test` environment, then run `./.ai-harness up`. Refer to [Laravel Sail](private-packages.md#laravel-sail). |

## Configuration errors

The harness stops when the configuration is not valid. Examples:

| Error | Cause |
| --- | --- |
| `Unknown configuration key [...]` | A key is misspelled or not supported. Refer to [All keys](configuration.md#all-keys). |
| `Duplicate configuration key [...]` | The same key occurs two times in one file. |
| `sail_services may only be set when services=sail.` | Set `services=sail`, or remove `sail_services`. |
| `cloud_seed requires cloud_migrate=true` | Set `cloud_migrate=true`, or set `cloud_seed=false`. |
| `herd_php must be empty or a major.minor version` | Use a value such as `8.4`. |
| `valet_php must be empty or a major.minor version` | Use a value such as `8.4`, not `php@8.4`. |
| `AI_HARNESS_ENV must be local, claude-cloud, or codex-cloud.` | Correct the environment variable. |

## Common problems

### MySQL port 3306 is already in use

Another MySQL server uses the port. Set a different port in `.env`, then run `./.ai-harness setup` again:

```dotenv
FORWARD_DB_PORT=3307
```

### The harness does not create MySQL databases

The harness manages databases only when `services=sail` and `mysql` is in `sail_services`. An empty `sail_services` starts the full stack, but the harness does not manage its databases. Refer to [MySQL with Sail](local-environments.md#mysql-with-sail).

### Herd commands fail with exit code 127

Herd can print PHP warnings when it looks up the PHP version of the site. Older harness versions then used the warning text as the program name. Update the package. The harness now finds the PHP binary through the Herd PHAR. Refer to [PHP version of the Herd site](local-environments.md#php-version-of-the-herd-site).

### Herd sites stay after you delete a worktree

Run `./.ai-harness prune-herd --dry-run` to see them. Then run `./.ai-harness prune-herd` to remove them. Refer to [Remove orphaned Herd sites](local-environments.md#remove-orphaned-herd-sites).

### Valet sites stay after you delete a worktree

Run `./.ai-harness prune-valet --dry-run` to see them. Then run `./.ai-harness prune-valet` to remove them. Refer to [Remove orphaned Valet sites](local-environments.md#remove-orphaned-valet-sites).

### A Herd or Valet command fails in Codex

Codex runs commands in a sandbox. Herd and Valet commands must run outside the sandbox. Approve the escalation request for the command.

### A Valet command asks for a password

Valet runs `link`, `secure`, `unsecure`, `unlink`, and `isolate` with `sudo`. An agent cannot type a password. Run `valet trust` one time. After that, Valet does not ask for a password.

### Composer hooks fail after an upgrade

Disable the scripts and refresh manually:

```bash
composer update mrkoopie/laravel-ai-harness --no-scripts
./vendor/bin/ai-harness update
```

For an upgrade from 0.1, refer to [Upgrading](upgrading.md#manual-upgrade).

### `cleanup` says that it cannot clean up without dependencies

`cleanup` needs `vendor/`. Run `composer install`, then run `cleanup` again. The ownership records stay. The next `cleanup` can then remove the resources.

### Cloud setup fails with `requires a committed composer.lock`

Commit `composer.lock` to the repository.

### Cloud setup uses the wrong PHP version

The image probably puts phpenv shims first in `PATH`. Add `export PATH="/usr/bin:$PATH"` to the setup and maintenance scripts. Refer to [phpenv shims](cloud.md#phpenv-shims).

### Cloud provisioning fails with `503 Service Unavailable` from Launchpad

The ondrej/php PPA on Launchpad often answers `503`. Set `AI_HARNESS_PHP_VERSION`, and add `packages.sury.org` to the network allowlist. Provisioning then uses the sury repository and leaves the PPA entries of the selected sources out. Refer to [PHP repository](cloud.md#php-repository).

### Cloud archive downloads are blocked

Set `AI_HARNESS_COMPOSER_PREFER=source` to install packages from Git. Refer to [Environment variables](cloud.md#environment-variables).
