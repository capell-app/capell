---
name: capell-theme-development
description: "Builds and proves Capell themes: scaffolding a theme package, registering its definition and presets, deciding what belongs in theme settings and blueprints versus markup, and verifying the theme on real public routes. Use when creating or changing a Capell theme, theme presets, theme assets, page or widget presentation, or when proving a theme renders safely on public routes."
---

# Capell Theme Development

Use this for theme packages and theme-level rendering. For architecture and extension points, use the `capell` skill. For seeding pages, layouts and widgets that a theme renders, use `capell-cms-development`. For package structure shared with other extensions, use `capell-extension-development`.

## Ownership rule

- If a value changes per site, page, widget, block, locale or editor decision, it belongs in Capell data: theme settings, a page or widget blueprint, widget or page content, Layout Builder widget assets, or block and container meta.
- If a value is only the HTML, CSS or component shape that displays that data, it belongs in the theme package.
- Seed minimal editable content and block configuration. Never seed designed page markup.
- Authoring behaviour stays out of themes. A theme renders public presentation only.

## Scaffold

```bash
php artisan capell:make:theme aurora --package=vendor/aurora-theme --path=packages --name="Aurora" --extends=default
php artisan capell:make:theme aurora --package=app/aurora-theme --local
```

`--local` scaffolds an app-owned theme whose provider registers unconditionally, so a fresh install can seed and render it in one process. Reusable packages keep the installed-package gate (`isPackageInstalled()`) in the provider. Start from the parent theme named in `--extends` unless its rendering contract is wrong, and keep only the changed sections and assets in the child.

## Register the theme

- `capell.json` declares `kind: "theme"`, a stable `themeKey` and, for a child theme, `extends`. The manifest value drives the public view chain.
- Register a `Capell\Core\ThemeStudio\Data\ThemeDefinitionData` (with `ThemePresetData` presets) through `Capell\Core\ThemeStudio\Theme\ThemeRegistry` from the provider's `packageBooted()`. Keep `key` stable: installed theme keys, preview tokens, cache keys and diagnostics depend on it.
- Register the theme CSS with the conditional Tailwind import `theme-css:<themeKey>`. A missing source or a different key leaves the theme silently unstyled.
- Present missing or unknown preset values safely. Do not recreate the token stylesheet in views; the frontend head emits it.
- Use `@frontendAsset('path/from/public.css')` for public assets, not root-relative `src` or `href` values.

Read `references/route-qa.md` before proving a theme on public routes.

## Validate

```bash
php artisan capell:theme:doctor aurora --path=packages/aurora-theme
php artisan capell:themes:validate aurora
php artisan capell:package:lint packages/aurora-theme
php artisan capell:frontend-tailwind-assets
php artisan capell:package-cache:clear
```

After changing a theme CSS source, its Tailwind registration or token utility names, regenerate the frontend assets and clear the package and config caches before checking a page. A successful provider boot does not prove the CSS reached the page.

## Public output rules

- Public Blade must not query the database or lazy-load relationships. It receives hydrated render data.
- Public output must not expose authoring controls, signed URLs, permissions, admin routes, field paths, schema labels, model IDs, package names or editor metadata.
- When a change affects shared behaviour, change the parent theme or shared runtime once. Do not copy identical logic across child providers.

## Theme tests

Cover, in layers: manifest validity, the registered definition and every advertised preset, safe asset URLs, preview images, optional-package fallbacks, and public rendering on a seeded route. Use `Capell\Core\Testing\ExtensionTestHarness` for the manifest checks:

```php
ExtensionTestHarness::forPath(__DIR__ . '/..')
    ->assertManifestValid()
    ->assertThemeManifest('aurora')
    ->assertThemeUsesSafeAssetUrls();
```

```bash
vendor/bin/pest packages/aurora-theme/tests
```
