# Local environments

This page tells you what the environment commands do on a local machine. For cloud containers, refer to [Cloud environments](cloud.md).

Each checkout or worktree gets its own databases, Herd site, and `.env.testing`. The names come from the checkout path. Thus, parallel worktrees do not share data. The harness does not read Git or agent metadata to do this.

## `setup`

`setup` prepares the current checkout. It does these steps in sequence:

1. Run `composer install` when `vendor/autoload.php` does not exist.
2. Copy `.env.example` to `.env` when `.env` does not exist.
3. Set the MySQL values in `.env` when Sail manages MySQL. The host is `127.0.0.1` for the native and Herd runtimes, and `mysql` for the Sail runtime. The harness activates commented default values. It does not add duplicates.
4. Start the configured Sail services. Create the development and testing databases for this checkout.
5. Link the directory in Herd when `runtime=herd`. Set `APP_URL` to the HTTPS URL of the site.
6. Secure the Herd site when `herd_secure=true`. Isolate its PHP version when `herd_php` has a value.
7. Generate `APP_KEY` when the key is empty.
8. Create `.env.testing` when it does not exist. With Sail MySQL, it uses the testing database. Without it, it uses isolated SQLite defaults.
9. With Sail MySQL, change the default SQLite entries in `phpunit.xml` to the Sail testing database.

`setup` does not:

- Run migrations.
- Change `compose.yaml`.
- Add test-runner options.

You can run `setup` again at any time. It does not overwrite an existing `.env` or `.env.testing`, and it does not create duplicate databases or Herd links.

## `cleanup`

`cleanup` removes only the resources that the harness created and recorded in `.ai-harness.state.json`:

1. It drops the MySQL databases of this checkout.
2. It removes HTTPS from the Herd site of this checkout.
3. It unlinks the Herd site of this checkout.

Before each Herd action, the harness makes sure that the recorded site name is the expected name for the current path. If the names are different, it stops and changes nothing.

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

### Codex sandbox

Codex runs commands in a sandbox. Herd commands must run outside the sandbox. The managed `AGENTS.md` block tells Codex to ask for escalation on the first attempt of each Herd command.

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

With Herd or native PHP, `DB_PORT` uses the `FORWARD_DB_PORT` value from `.env`. The default is `3306`. When another MySQL server already uses port 3306, set a different port:

```dotenv
FORWARD_DB_PORT=3307
```

### Cleanup of parallel test databases

MySQL cleanup also removes the numeric parallel test databases of this checkout, for example `my_app_1a2b3c4d5e_testing_1` and `my_app_1a2b3c4d5e_testing_test_1`.

Cleanup keeps:

- Databases with a similar name but without a numeric worker suffix.
- Databases of other checkouts.

When database cleanup fails, the harness keeps the ownership record. You can then run `cleanup` again.

## State file

The harness records owned resources in `.ai-harness.state.json`. Git ignores this file. Do not edit it by hand. When you delete it, `cleanup` does not remove the resources that were in it.
