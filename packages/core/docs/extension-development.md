# Extension and Theme Development

This guide is the cold-start path for a third-party Capell package: scaffold it,
load it in a development app, prove it renders, and prepare a Marketplace
submission. The generated package `README.md` is the source of truth for that
package's own commands and dependencies; keep it with the package and update it
when you add a public surface.

## 1. Scaffold

Use the colon commands for new work. The older hyphenated commands remain
available for compatibility, but generated instructions use these names:

```bash
php artisan capell:make:extension acme/notice-board \
  --profile=full \
  --path=packages \
  --name="Notice Board"

php artisan capell:make:theme aurora \
  --package=acme/aurora-theme \
  --path=packages \
  --name="Aurora" \
  --extends=default
```

Choose `minimal` when you only need a package provider and manifest. Choose
`full` when you want the working example widget, settings, typed input and
render Data objects, widget assets, and tests. A generated theme includes its
provider, manifest, CSS source, and a rendering test.

Do not hand-copy a first-party package directory. The generators use only the
public package contracts and avoid Capell's internal development overlay.

## 2. Read the generated package before editing it

`capell.json` is the package's runtime contract. Keep its package name, version,
providers, contributions, assets, and Marketplace metadata consistent with
`composer.json`. A full extension has an example content widget blueprint; use
it as the starting point for a real widget rather than placing rendering queries
in a Blade view.

For themes, the `themeKey` value in `capell.json` is also the CSS registration key.
The provider must register the package CSS with the matching conditional
Tailwind import:

```php
condition: 'theme-css:aurora',
```

This is intentionally not a requirement for a literal
`theme-registration.css` file in every theme. Capell collects the registered
Tailwind sources and produces the applicable bundle. A missing CSS source or a
different key silently leaves the theme unstyled, so treat the condition as part
of the theme's public contract.

If you introduce a new design token, register its **utility name** in every
frontend bundle that needs it, alongside the token source. Adding only a token
value does not make a Tailwind utility available. This is per bundle: a utility
registered for an admin bundle is not automatically available to a public theme
bundle.

## 3. Load the package in a development app

Use a normal Composer path repository in the consuming application's
`composer.json` while developing locally:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "packages/notice-board",
            "options": { "symlink": true }
        }
    ]
}
```

Then install it by Composer name:

```bash
composer require acme/notice-board:@dev
php artisan capell:package-cache:clear
php artisan config:clear
```

The package manifest is cached. Run both cache-clearing commands whenever you
change `capell.json`, especially after renaming a contribution or settings
class. Otherwise a warm app can keep using a deleted class or omit a newly added
widget even though the package files are correct.

For a published package, replace `@dev` with a tagged normal Composer
constraint such as `^1.0`.

## 4. Validate before rendering

Run both commands from the consuming app. They share the extension-audit
contract: any result with `severity=error` returns a failing exit code.

```bash
php artisan capell:package:lint packages/notice-board
php artisan capell:extension-audit packages/notice-board
```

The lint checks the manifest, naming and version sanity, referenced assets, and
theme CSS registration. The audit additionally verifies the declared extension
contracts. Fix errors rather than suppressing them; scaffold output should be
clean from its first run.

Useful failures and their fixes:

| Failure                                                         | What to check                                                                                                      |
| --------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------ |
| A theme reports no `theme-css:<key>` registration               | Use the exact `theme` key from `capell.json` in the provider's conditional Tailwind CSS registration.              |
| A theme CSS source is missing                                   | Restore or configure the source file that the provider registers, then regenerate the frontend assets.             |
| A contribution or settings class cannot be found after a rename | Run `capell:package-cache:clear` and `config:clear`; confirm the manifest class name and Composer PSR-4 namespace. |
| A screenshot or Marketplace asset is missing                    | Keep the referenced path package-relative and commit the file before publishing.                                   |

Run the generated package tests as you extend the blueprint. The README names
the smallest relevant command for that package.

## 5. Prove it renders

For a full extension, open **Admin → Pages**, edit a development page, and find
its **Layout Builder** field. In the `main` area choose **Add widget**, select
`<package display name> example`, enter `Dogfood heading` for **Heading** and
`Rendered by the generated widget.` for **Body**, then save. Preview or open
that page publicly and confirm both strings render and the package's
`widget.css`/`widget.js` resources load. The generated widget key is the Composer
package name with `/` changed to `.`, for example `acme.notice-board`.

Replace the example data with an Action or Data object that supplies only
public, cache-safe render state.

For a theme, select the theme in the development app, request a public page,
and verify that its CSS is in the generated frontend output. After changing a
theme CSS source, its Tailwind registration, or token utility names, regenerate
the app's frontend Tailwind assets, then clear the package and configuration
caches before checking the public page again:

```bash
php artisan capell:frontend-tailwind-assets
php artisan capell:package-cache:clear
php artisan config:clear
```

A successful provider boot is not proof that the conditional CSS made it into
the page.

## 6. Prepare a Marketplace submission

Keep two versions distinct:

- `manifest-version` describes the `capell.json` schema (currently manifest v3).
- `version` is your package release version and should match the published
  Composer release.

Neither field sets Marketplace maturity. Third-party authors submit through the
Marketplace submission flow with their repository, package metadata, support,
privacy, security, data, and third-party disclosures. Reviewers assign the
Marketplace state. An approved community submission starts in **Labs**;
Marketplace **Beta** acknowledgement is a purchaser-install-flow requirement,
not an author-controlled manifest field. The public label for stable is
**Released**.

Do not create or copy Capell's first-party release ledgers
(`docs/release-catalogue.json` or `config/release-packages.json`) into a
third-party package. They are Capell-owned operational records, not a package
publishing interface.

Before submitting, tag the Composer release, run the package's tests and both
validation commands, include accurate Marketplace metadata and package-relative
assets, and confirm the package installs through a normal Composer repository.

## Next reading

- [Package authoring](../../../docs/platform/package-authoring.md)
- [Package anatomy](../../../docs/packages/package-anatomy.md)
- [Frontend extensions](../../../docs/packages/frontend-extensions.md)
- [Creating custom themes](../../../docs/packages/creating-custom-themes.md)
- [Marketplace extension contracts](../../../docs/packages/marketplace-extension-contracts.md)
