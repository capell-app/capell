# Creating the Capell 0.0.x Monorepo Branches

![Capell Creating the Capell 0.0.x Monorepo Branches screenshot](../images/admin-dashboard.png)

Use this guide when setting up a fresh Capell development workspace where the host repository and the first-party package repository need to work together on the `main` branch.

## Expected Layout

Keep both repositories as siblings:

```sh
~/Sites/packages/capell/
├── capell-4/
└── capell-packages-4/
```

`capell-4` is the host monorepo for `capell-app/core`, `capell-app/admin`, `capell-app/frontend`, and related host packages. `capell-packages-4` is the local checkout of the first-party add-on package monorepo at `capell-app/capell-packages`.

## Clone Or Create The Repositories

Clone the host repository, then create a local `main` branch from the remote branch:

```sh
mkdir -p ~/Sites/packages/capell
cd ~/Sites/packages/capell

git clone git@github.com:capell-app/capell.git capell-4
cd capell-4
git fetch origin
git switch --track origin/main
```

If the host repository has no `main` branch yet, run this from `capell-4`:

```sh
git switch main && git pull --ff-only && git switch -c main && git push -u origin main
```

Clone the add-on packages repository beside it and switch to its `main` branch:

```sh
cd ~/Sites/packages/capell

git clone git@github.com:capell-app/capell-packages.git capell-packages-4
cd capell-packages-4
git fetch origin
git switch --track origin/main
```

If the add-on packages repository has no `main` branch yet, run this from `capell-packages-4`:

```sh
git switch main && git pull --ff-only && git switch -c main && git push -u origin main
```

## Wire Composer To The Local Packages

In the Laravel application used for testing Capell, add path repositories that point to both local checkouts:

```json
"repositories": [
    { "type": "path", "url": "../packages/capell-4/packages/*", "symlink": true },
    { "type": "path", "url": "../packages/capell-packages-4/packages/*", "symlink": true }
]
```

The paths must be relative to the Laravel application root. Adjust the leading `../packages/` segment if your app lives elsewhere.

Then update the app dependencies:

```sh
composer update -W
composer dump-autoload
```

## Keep Both Repositories Aligned

Before working on a change that spans host and add-on packages:

```sh
cd ~/Sites/packages/capell/capell-4
git switch main
git pull --ff-only

cd ~/Sites/packages/capell/capell-packages-4
git switch main
git pull --ff-only
```

Create feature branches from `main` in whichever repository needs changes:

```sh
git switch -c feat/my-capell-change
```

Use the same branch name in both repositories when a change spans them. That keeps pull requests and CI easier to pair up.

## Verify The Workspace

From the host repository:

```sh
composer prepare
composer test
composer preflight
```

From the Laravel application, confirm Composer is using symlinks:

```sh
composer show capell-app/core -P
composer show capell-app/content-sections -P
```

Both commands should resolve to your local `capell-4` or `capell-packages-4` checkout rather than a Composer cache path.

## Next

- [Development](index.md)
- [Local development](local-development.md)
- [Host, package, or app code](package-boundaries.md)
