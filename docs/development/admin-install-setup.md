# Admin Install And Setup

![Capell Admin Install And Setup screenshot](../images/admin-dashboard.png)

Capell Admin has two package lifecycle commands:

- `capell:admin-install` installs admin settings migrations, syncs Shield and Capell permissions, seeds the default admin roles, caches Filament components, and can apply panel integration.
- `capell:admin-setup` creates demo/default admin content and can integrate the package into a Filament panel.

During a full `capell:install`, Capell runs package lifecycle commands in two phases:

1. It resolves the selected packages and their dependencies, then runs every package `install` command in dependency order.
2. After all selected packages have finished installing, it runs every package `setup` command, again in dependency order.

This ordering is intentional. A setup command may depend on another package already being installed, migrated, registered, or marked as installed. For example, an add-on setup command can safely assume its required packages have completed their install commands before setup begins.

For `capell-app/admin`, that means `capell:admin-install` may be followed by `capell:admin-setup` in the same full install run.

Because of that, avoid adding the same side effect to both commands. Permission sync belongs to `capell:admin-install`. The full install flow passes `--skip-permission-sync` into `capell:admin-setup` so setup does not rerun install-mode permission seeding after install has already run.

Direct `capell:admin-setup` still syncs permissions unless `--skip-permission-sync` is passed explicitly. This keeps standalone setup useful while protecting the full install flow from duplicate `syncPermissions()` calls.

When adding new install or setup behavior, check whether the full install flow will execute both commands. If the behavior belongs to package installation, keep it in the install command and pass an explicit skip flag into setup. If setup needs to run independently, make the setup command's behavior opt out rather than relying on model or role existence heuristics.

## Next

- [Development](index.md)
- [Artisan commands](artisan-commands.md)
- [Command index](commands.md)
