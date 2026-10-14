# Local environments

This page tells you what the environment commands do on a local machine. For cloud containers, refer to [Cloud environments](cloud.md).

Each checkout or worktree gets its own databases, Herd or Valet site, and `.env.testing`. The names come from the checkout path. Thus, parallel worktrees do not share data. Resource names do not depend on Git or agent metadata; Git metadata is used only to find inherited personal configuration.

## `setup`

`setup` prepares the current checkout. It does these steps in sequence:

1. Load configuration and check local override conflicts before making changes.
2. Copy `.env.example` to `.env` when `.env` does not exist.
3. Apply `local_env.*` overrides to `.env` and clear cached Laravel configuration when overrides are configured.
4. Set the MySQL values in `.env` when Sail manages MySQL. Host PHP uses `127.0.0.1`; PHP inside Sail uses `mysql`. The harness activates commented default values and removes duplicates.
5. Run `composer install` when `vendor/autoload.php` does not exist.
6. Start the configured Sail services. Create the development and testing databases for this checkout.
7. Link the directory in Herd when `runtime=herd`, or in Valet when `runtime=valet`. Set `APP_URL` to the URL of the site.
8. Secure the site when `herd_secure=true` or `valet_secure=true`. Isolate its PHP version when `herd_php` or `valet_php` has a value.
9. Generate `APP_KEY` when the key is empty.
10. Create `.env.testing` when it does not exist. With Sail MySQL, it uses the testing database. Without it, it uses isolated SQLite defaults.
11. With Sail MySQL, change the default SQLite entries in `phpunit.xml` to the Sail testing database.

`setup` does not:

- Run migrations.
- Change `compose.yaml`.
- Add test-runner options.

You can run `setup` again at any time. It does not replace an existing `.env` or `.env.testing` file, and it does not create duplicate databases or site links. But it writes the harness-owned values again on each run:

- With Sail MySQL: the `DB_*` values in `.env` and `.env.testing`.
- With Herd or Valet: `APP_URL` in `.env`.

If you change these values by hand, the next `setup` replaces them.

Personal `local_env.*` settings in `.ai-harness.config.local` are also reapplied to `.env` on each setup, before Composer, Sail, or Artisan runs. Linked local worktrees inherit the primary checkout's local file, with their own local file taking precedence. See [Local application environment overrides](configuration.md#local-application-environment-overrides) for syntax, protected values, testing behavior, and conflict checks.

## `cleanup`

`cleanup` removes only the resources that the harness created and recorded in `.ai-harness.state.json`:

1. It drops the MySQL databases of this checkout.
2. It removes HTTPS from the Herd or Valet site of this checkout.
3. It unlinks the Herd or Valet site of this checkout.

Before each Herd or Valet action, the harness makes sure that the recorded site name is the expected name for the current path. If the names are different, it stops and changes nothing.

`cleanup` does not stop Sail. Use `down` for that. The generated Codex local environment runs `cleanup` and then `down`.

## `up` and `down`

`up` starts the configured Sail services. `down` stops them.

| `sail_services` | `up` runs | `down` runs |
| --- | --- | --- |
| Empty | `sail up -d` | `sail down` |
| `mysql,redis` | `sail up -d mysql redis` | `sail stop mysql redis` |

With `runtime=sail`, the harness adds the `laravel.test` container to the list. When no Sail containers are configured, `up` and `down` do nothing.

The harness never deletes Docker volumes.

## Laravel Herd

With `runtime=herd`, `setup` links the checkout as a Herd site.

- The site name comes from the directory name plus a short hash of the full path, for example `my-app-1a2b3c4d5e`.
- `APP_URL` becomes `https://<site-name>.test`.
- `herd_secure=true` runs `herd secure`. When you change it to `false`, the next `setup` runs `herd unsecure`.
- `herd_php=8.4` runs `herd isolate 8.4`.

### PHP version of the Herd site

For a standard Herd installation, `php`, `artisan`, `test`, and `composer` use the PHP binary of the Herd site directly. The harness finds this binary through the Herd PHAR. Thus, startup warnings from Herd cannot change the command.

The harness uses the binary only when it is an executable, versioned PHP binary in the Herd installation directory. In all other cases, it uses the `herd` wrapper, as before. When the first argument is `--site=...`, the harness also uses the wrapper.

### Remove orphaned Herd sites

When you delete a worktree without `cleanup`, its Herd site stays linked. Use `prune-herd` to find and remove these sites:

```bash
./.ai-harness prune-herd --dry-run
./.ai-harness prune-herd
```

- The command shows only links whose target directory no longer exists and whose name agrees with the harness naming scheme. It also recognizes the naming scheme of earlier versions.
- In an interactive run, it asks for each site. The default answer is to keep the site.
- `--dry-run` and `--no-interaction` only show the sites. They change nothing.
- `--sites-path=/path` shows the sites in a different directory. It never removes sites outside the Herd sites directory.
- Before it removes HTTPS and before it unlinks, it checks the site again. If the site changed, it stops.
- If a Herd command fails, the link stays, so that you can try again.
- It never deletes databases.
- It works only locally, not in a cloud environment.

A moved or copied worktree still fails the ownership check in `setup` and `cleanup`. Use `prune-herd` to remove the old Herd link after a move. The harness does not remove the databases of a moved worktree automatically.

### Codex sandbox

Codex runs commands in a sandbox. Herd commands must run outside the sandbox. The managed `AGENTS.md` block tells Codex to ask for escalation on the first attempt of each Herd command.

## Laravel Valet

With `runtime=valet`, `setup` links the checkout as a Valet site. Valet runs only on macOS.

- The site name is the same as for Herd, for example `my-app-1a2b3c4d5e`.
- `APP_URL` becomes `https://<site-name>.<tld>`. The harness reads the TLD from `~/.config/valet/config.json`. The default is `test`. With `valet_secure=false`, the URL uses `http`.
- `valet_secure=true` runs `valet secure <site>`. When you change it to `false`, the next `setup` runs `valet unsecure <site>`.
- `valet_php=8.4` runs `valet isolate php@8.4 --site=<site>`. When this PHP version is not installed, Valet installs it with Homebrew.
- `artisan`, `test`, `php`, and `composer` run through `valet php --site=<site>` and `valet composer --site=<site>`. Thus, they use the PHP version of the site. When the first argument of `php` or `composer` is `--site=...`, the harness does not add its own site.

The harness always gives the site name to Valet. Without it, Valet uses the directory name, and that is not the name of the site.

### Remove orphaned Valet sites

When you delete a worktree without `cleanup`, its Valet site stays linked. `prune-valet` finds and removes these sites in `~/.config/valet/Sites`:

```bash
./.ai-harness prune-valet --dry-run
./.ai-harness prune-valet
```

It has the same options and the same safety checks as `prune-herd`. Refer to [Remove orphaned Herd sites](#remove-orphaned-herd-sites).

### Password prompts

Valet runs `link`, `secure`, `unsecure`, `unlink`, and `isolate` with `sudo`. An agent cannot type a password. Run `valet trust` one time before you use `runtime=valet`.

### Codex sandbox

Valet commands must also run outside the Codex sandbox. The managed `AGENTS.md` block tells Codex to ask for escalation on the first attempt of each Valet command.

## MySQL with Sail

The harness manages databases only when `services=sail` and `mysql` is in the `sail_services` list. With an empty list (the full stack), the harness does not create databases or change `.env` for MySQL.

### Database names

The database names come from the directory name plus a short hash of the full path:

| Database | Example |
| --- | --- |
| Development | `my_app_1a2b3c4d5e` |
| Testing | `my_app_1a2b3c4d5e_testing` |

The names leave space for the parallel worker suffix of Laravel. They stay within the MySQL limit of 64 characters.

### Port

With host PHP (Herd, Valet, or native), `DB_PORT` uses the `FORWARD_DB_PORT` value from `.env`. The default is `3306`. To keep a personal port choice across fresh worktrees, put this in the primary checkout's `.ai-harness.config.local`, then run `./.ai-harness setup`:

```ini
local_env.FORWARD_DB_PORT=3307
```

PHP inside Sail still connects to `mysql:3306`. You can also set `FORWARD_DB_PORT` directly in `.env`, but that choice is not inherited by new worktrees. For an existing database outside Sail management, use `local_env.DB_PORT=3307` instead. Refer to [Local application environment overrides](configuration.md#local-application-environment-overrides).

### Cleanup of parallel test databases

MySQL cleanup also removes the numeric parallel test databases of this checkout, for example `my_app_1a2b3c4d5e_testing_1` and `my_app_1a2b3c4d5e_testing_test_1`.

Cleanup keeps:

- Databases with a similar name but without a numeric worker suffix.
- Databases of other checkouts.

When database cleanup fails, the harness keeps the ownership record. You can then run `cleanup` again.

## State file

The harness records owned resources in `.ai-harness.state.json`. Git ignores this file. Do not edit it by hand. When you delete it, `cleanup` does not remove the resources that were in it.
