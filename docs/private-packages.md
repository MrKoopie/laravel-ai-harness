# Private packages

Use the `COMPOSER_AUTH` environment variable to give Composer the credentials for private packages. Composer reads this variable itself. The harness does not change it. It only makes sure that the variable gets to Composer in each runtime, and `doctor` validates it.

One variable can contain credentials for many hosts and many authentication types.

## Set `COMPOSER_AUTH`

The value is one JSON object. It has the same structure as `auth.json`. Composer merges it with `auth.json` and the global credentials.

```json
{
    "http-basic": {
        "repo.example.com": {"username": "deploy", "password": "..."},
        "satis.example.org": {"username": "ci", "password": "..."}
    },
    "github-oauth": {"github.com": "ghp_..."},
    "gitlab-token": {"gitlab.example.com": {"username": "deploy", "token": "glpat-..."}},
    "bearer": {"packages.example.com": "..."}
}
```

Put the JSON on one line. Add the export to your shell profile, for example `~/.zshrc`, or to your secret manager:

```bash
export COMPOSER_AUTH='{"http-basic":{"repo.example.com":{"username":"deploy","password":"..."}},"github-oauth":{"github.com":"ghp_..."}}'
./.ai-harness composer install
```

### Supported authentication types

| Type | Value for each host |
| --- | --- |
| `http-basic` | `{"username": "...", "password": "..."}` |
| `bearer` | A token string. |
| `github-oauth` | A token string. |
| `gitlab-token` | A token string, or `{"username": "...", "token": "..."}`. |
| `gitlab-oauth` | A token string, or `{"token": "..."}` with optional `refresh-token` and `expires-at`. |
| `bitbucket-oauth` | `{"consumer-key": "...", "consumer-secret": "..."}`. |
| `forgejo-token` | `{"username": "...", "token": "..."}`. |
| `custom-headers` | A list of `"Name: value"` headers. |
| `client-certificate` | `{"local_cert": "/path/to/cert.pem"}` with optional `local_pk` and `passphrase`. |

You can also add `github-domains` and `gitlab-domains` for GitHub Enterprise and self-hosted GitLab. Refer to [Authentication for private packages](https://getcomposer.org/doc/articles/authentication-for-private-packages.md) in the Composer documentation.

## Validate the value

```bash
./.ai-harness doctor
```

When `COMPOSER_AUTH` is set, `doctor` shows the authentication types and hosts. It never shows usernames, passwords, tokens, or headers:

```text
OK COMPOSER_AUTH is valid for http-basic (repo.example.com, satis.example.org); github-oauth (github.com)
```

`doctor` fails when:

- The value is not valid JSON, or it is not a JSON object.
- A key is not a known authentication type, for example `http_basic` instead of `http-basic`.
- A host entry does not have the necessary fields, for example `http-basic` without `password`.
- The value contains no credentials.

When a key does not look like a type name or a host name, `doctor` does not show it, because it can be a secret in the wrong place. An empty `COMPOSER_AUTH` has no effect, as in Composer.

## Runtimes

| Runtime | How `COMPOSER_AUTH` gets to Composer |
| --- | --- |
| Native PHP | Composer runs on the host and gets the variable automatically. |
| Laravel Herd | `herd composer` or the Herd PHP runs on the host and gets the variable automatically. |
| Laravel Valet | `valet composer` runs on the host and gets the variable automatically. |
| Laravel Sail | Composer runs in the `laravel.test` container. You must forward the variable in the compose file. |
| Claude and Codex cloud | Set the variable in the cloud environment. Refer to [Cloud](#cloud). |

The first bootstrap install in `.ai-harness` also runs Composer on the host, so it also gets the variable.

### Laravel Sail

Sail forwards only a fixed list of variables into the container. `COMPOSER_AUTH` is not in that list. Add it to the `laravel.test` service in `compose.yaml` or `docker-compose.yml`:

```yaml
services:
    laravel.test:
        environment:
            COMPOSER_AUTH: '${COMPOSER_AUTH:-}'
```

Then start the container again, so that Docker Compose creates it with the new variable:

```bash
./.ai-harness up
```

Do this again each time that you change `COMPOSER_AUTH`. You can also add it to `compose.override.yaml`. When you use `SAIL_FILES`, add the line to one of those files.

With `runtime=sail`, `doctor` checks that the compose file forwards `COMPOSER_AUTH`. It checks only the file, not the container that runs now.

Docker keeps the value in the container configuration. Any user who can run `docker inspect` can read it. Use this only on a development computer.

### Cloud

Set `COMPOSER_AUTH` as a secret or environment variable in the cloud environment settings. Cloud setup runs `composer install` with it.

- **Claude cloud:** set the variable in the environment settings. The setup script and the `SessionStart` hook both get it.
- **Codex cloud:** secrets are available only during the setup script. The maintenance script does not get them. Thus, maintenance cannot install a new private package. Refer to [Codex secrets](cloud.md#secrets).

The network policy of the environment must allow the hosts of your private repositories.

## Security

- Do not put credentials in `composer.json`, `.ai-harness.config`, or other tracked files.
- Do not put `COMPOSER_AUTH` in a command that goes into the shell history. Set it in your shell profile, a secret manager, or the environment settings of the provider.
- The harness never writes `COMPOSER_AUTH` to a file and never prints its credentials.
