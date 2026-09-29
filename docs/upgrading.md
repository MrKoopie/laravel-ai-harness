# Upgrading

## Normal upgrades

Update the package with Composer:

```bash
composer update mrkoopie/laravel-ai-harness
```

The Composer hook refreshes the managed project files automatically. Commit the changed files. Refer to [Installation](installation.md#update-the-package).

When you use cloud environments, make sure that the refreshed `.ai-harness-cloud` is committed.

## Upgrade from version 0.1

Version 0.2 keeps the old `artisan ai-harness:update` command and the old service provider class for one release. Thus, an existing v0.1 Composer hook can complete the upgrade. The compatibility command replaces the old hook with the new guarded `./vendor/bin/ai-harness update` hook.

### Automatic upgrade

Run `composer update mrkoopie/laravel-ai-harness`. The old hook calls the compatibility command, which migrates the configuration and writes the new files.

### Manual upgrade

For recovery, disable the Composer scripts and run the compatibility command yourself:

```bash
composer update mrkoopie/laravel-ai-harness --no-scripts
php artisan ai-harness:update --ansi
```

Use the Artisan command, not `./vendor/bin/ai-harness update`. The Artisan command migrates the old configuration and environment choices before it creates the new project files.

### What the migration does

When no `.ai-harness.config`, `.ai-harness.config.dist`, or `.ai-harness.config.local` exists, the bridge translates the old choices:

- Codex, Claude, Herd, Docker, and PHP version choices become the new keys.
- Old Docker support becomes `services=sail` with `sail_services=mysql`.

An existing configuration in the new format always wins.

The bridge does not delete files silently. It shows a warning for:

- Unsupported skills generation.
- Unsupported Polyscope generation.
- Obsolete generated files.

Remove these files yourself after you read the warnings.

### After the first refresh

Use `./.ai-harness update` from then on. The Artisan compatibility command is temporary. A later release, possibly 0.3, can remove it.
