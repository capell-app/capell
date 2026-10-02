# Local Development & Testing


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
- If you are setting up the host and add-on repositories from scratch, follow [Creating the Capell 1.x Monorepo Branches](monorepo-1x-branch.md) first.
- Symlinks allow instant reflection of code changes without reinstalling packages.
- For VCS repositories and other dependencies, see the main install guide in [README.md](../README.md).

## Troubleshooting

- If packages are not detected, check your path and symlink permissions.
- If you see autoload errors, run `composer dump-autoload`.
- For cache issues, see [Server Configuration](https://docs.capell.app/packages/frontend/server-config/) and the [Frontend guide](../frontend/guide.md).

### A test fails locally but passes in CI

Nothing syncs `vendor/` after you pull a commit that bumps a dependency, so the working tree keeps running the old package while CI installs fresh. Compare every package version in `composer.lock` with `vendor/composer/installed.json` (an `installed.json` older than the lock is the tell) before reading any source, and run `composer install` if they differ.

Techniques that help once drift is ruled out:

- Compare a vendor package's versions without touching `vendor/` by unzipping the archives in `$(composer config --global cache-files-dir)/<vendor>/<package>/`.
- To check for a CI coverage hole, run the matrix cell's exact filter (for example `pest --testsuite=Unit --group=frontend`). Groups come from `tests/Pest.php`; cells come from `scripts/test-all/TestAllMatrix.php`.
- For a pristine baseline, `git worktree add --detach <path outside the repository> <commit>`, then provision it as described in [Git worktrees](worktrees.md).
- A shared primary checkout can show green while committed `main` is red, because uncommitted fixes in the working tree mask the failure. Check a pristine worktree of the commit before trusting it.
- A scratch note left in the repository root fails `scripts/check-root-docs.php`.

---

**Further Reading:**

- [README.md](../README.md)
- [Configuration Reference](configuration.md)
- [Server Configuration](https://docs.capell.app/packages/frontend/server-config/)
- [Frontend guide](../frontend/guide.md)
