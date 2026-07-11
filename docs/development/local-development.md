# Local Development & Testing

![Capell Local Development & Testing screenshot](../images/admin-dashboard.png)

This guide explains how to set up local path repositories for Capell and related packages, enabling rapid development and testing with symlinked sources.

## When to Use

- Use these path repositories if you are actively developing Capell packages or want to test local changes before publishing.
- Not required for production or standard installs.

## Composer Path Repositories Example

Add the following to your `composer.json` under the `repositories` key:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../capell-4/packages/*",
            "options": {
                "symlink": true
            }
        }
    ]
}
```

- This example assumes your Laravel app sits beside `capell-4`. Adjust the `url` so it points from the app root to this repository's `packages/*` directory.
- After updating `composer.json`, run:

```sh
composer update
```

## Notes

- Ensure your local paths are correct relative to your Laravel project root.
- If you are setting up the host and add-on repositories from scratch, follow [Creating the Capell 4.x Monorepo Branches](monorepo-4x-branch.md) first.
- Symlinks allow instant reflection of code changes without reinstalling packages.
- For VCS repositories and other dependencies, see the main install guide in [README.md](../README.md).

## Troubleshooting

- If packages are not detected, check your path and symlink permissions.
- If you see autoload errors, run `composer dump-autoload`.
- For cache issues, see [Server Configuration](https://docs.capell.app/packages/frontend/server-config/) and the [Frontend guide](../frontend/guide.md).

---

**Further Reading:**

- [README.md](../README.md)
- [Configuration Reference](configuration.md)
- [Server Configuration](https://docs.capell.app/packages/frontend/server-config/)
- [Frontend guide](../frontend/guide.md)
