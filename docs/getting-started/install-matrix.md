# Install Matrix


Use this page to choose the right install path. For the full walkthrough, use the [install guide](install.md).

| Situation                     | Use                                                             | Notes                                                                        |
| ----------------------------- | --------------------------------------------------------------- | ---------------------------------------------------------------------------- |
| Fast local demo               | [Quickstart](quickstart.md)                                     | Uses demo-friendly defaults and optional package prompts.                    |
| Fresh Laravel app             | [Install guide, Path A](install.md#path-a-fresh-laravel-app)    | Best route for a clean production app.                                       |
| Existing Laravel app          | [Install guide, Path B](install.md#path-b-existing-laravel-app) | Preserve users, routes, deploy flow, and existing data.                      |
| Local monorepo development    | [Development](../development/index.md)                          | Use sibling `capell-4` and `capell-packages-4` repos with path repositories. |
| CI or non-interactive install | `capell:install --no-interaction` with all required flags       | Pass URL, admin user, package mode, theme, and cache flags explicitly.       |
| Optional package install      | Package README or package docs                                  | Confirm commands with `php artisan list capell`.                             |
| Upgrade existing app          | [Operations upgrade notes](../operations/upgrading.md)          | Back up the database and include optional package migrations.                |

## Non-Interactive Flags

For CI, seed jobs, or agent runs, avoid prompts:

```bash
php artisan capell:install \
  --no-interaction \
  --url=https://example.com \
  --name="Admin User" \
  --email=admin@example.com \
  --password="change-this-password"
```

Use additional install flags for package mode, theme choice, cache clearing, and welcome route setup when the app needs them.

## Next

- [Install guide](install.md)
- [Development](../development/index.md)
- [Operations](../operations/index.md)
