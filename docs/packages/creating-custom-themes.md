# Creating Custom Themes

Theme CSS and JavaScript use the typed [Frontend resource graph](../../packages/frontend/docs/frontend-resources.md). Editor metadata may select trusted groups or local paths, but cannot introduce remote or inline executable code.

Create a custom theme as a Capell package when it should be installable, testable, and reusable across apps. Keep one-off project styling in the app only when it depends on private app code.

## Package Shape

A theme package should include:

- `composer.json`
- `capell.json`
- a service provider
- a `ThemeDefinitionData` registration
- a page wrapper and views owned by the package
- public CSS/JS assets registered from PHP
- preset definitions with preview metadata
- package tests for manifest, definition registration, presets, preview image, and public-output safety

Themes that extend Foundation should declare the parent through package metadata and keep only the changed sections/assets in the child package.

## Project-Local Themes

For client-specific builds, scaffold a path package inside the host app:

```bash
php artisan capell:make-theme equidynamics \
  --package=app/equidynamics-theme \
  --name=Equidynamics \
  --local
```

The generated package is designed for `repositories` entries such as
`{"type": "path", "url": "packages/*", "options": {"symlink": true}}`.
It includes `capell.json`, Composer metadata, a runtime provider, a page wrapper,
a starter section view, a CSS file, and a package contract test.

Use `--local` for app-owned themes. Local providers register their views and
runtime definition unconditionally so a fresh install can seed, install, and
render the theme in the same process. Reusable marketplace themes should keep
the installed-package runtime gate.

Run the theme doctor after wiring Composer:

```bash
php artisan capell:theme:doctor equidynamics --path=packages/equidynamics-theme
```

The doctor validates the manifest, Composer metadata, view directory, safe asset
URLs, and runtime registration. It bootstraps runtime providers from the local
manifest during diagnostics so a path package can be checked before release.

## Register The Theme

Register the definition with `ThemeRegistry` from the package service provider:

```php
use Capell\Core\ThemeStudio\Theme\ThemeRegistry;

public function packageBooted(): void
{
    if (! $this->isPackageInstalled()) {
        return;
    }

    resolve(ThemeRegistry::class)->register($this->definition());
}
```

Local themes use the same registration call without the installed-package guard,
so a fresh install can discover the path package in the same process. The
definition controls the theme's metadata, presets, assets, and runtime:

```json
{
    "kind": "theme",
    "extends": "default",
    "themeKey": "agency-launch"
}
```

The package manifest's `extends` value drives the public view-chain resolution.
At runtime, the child package and its parent definition must be registered and
available through `CapellCore` and `ThemeRegistry`; the parent does not need to
be installed. Capell uses the parent manifest's `themeKey` to find that
definition.

`ThemeDefinitionData::$extends` remains supported metadata for local providers
and admin diagnostics. A provider can mirror the manifest value there, but it
does not select the public view chain:

```php
use Capell\Core\Enums\FrontendRuntime;
use Capell\Core\ThemeStudio\Data\ThemeDefinitionData;
use Capell\Core\ThemeStudio\Data\ThemePresetData;
use Capell\Core\ThemeStudio\Theme\ThemeRegistry;

public function packageBooted(): void
{
    resolve(ThemeRegistry::class)->register(
        new ThemeDefinitionData(
            key: 'agency-launch',
            name: 'Agency Launch',
            description: 'Portfolio and lead generation theme for service businesses.',
            package: 'capell-app/theme-agency-launch',
            previewImage: '/vendor/capell/theme-agency-launch/preview.jpg',
            tags: ['portfolio', 'lead-generation'],
            bestFit: ['agency', 'consulting'],
            includedSections: ['navigation', 'hero', 'features', 'footer'],
            presets: [
                new ThemePresetData(
                    key: 'editorial-warmth',
                    name: 'Editorial Warmth',
                    description: 'Warm accent palette with editorial spacing.',
                    previewImage: '/vendor/capell/theme-agency-launch/presets/editorial-warmth.jpg',
                    values: [
                        'primaryColor' => '#0f766e',
                        'accentColor' => '#f59e0b',
                        'radius' => 'sm',
                    ],
                ),
            ],
            assets: [
                'frontend' => '/vendor/capell/theme-agency-launch/theme.css',
            ],
            runtime: FrontendRuntime::Livewire,
            extends: 'default',
        ),
    );
}
```

Keep `key` stable. Installed `themes.key`, preview tokens, cache keys, and diagnostics all rely on it.

## Presets

Presets are explicit choices, not hidden defaults. Each preset needs:

- stable key
- human label
- short description
- preview image path
- token values using the shared vocabulary in [Frontend themes](../frontend/themes.md#brand-tokens)

Package-specific values are allowed, but public theme code must handle missing or unknown values safely.

## Theme Editor Extension

Package themes can extend the admin Theme Editor by binding a class that implements
`Capell\Admin\Contracts\Themes\ThemeEditorExtension` and tagging it with
`ThemeEditorExtension::TAG`.

Use an extension when a package needs:

- extra editor sections or fields
- package-specific preview sample content
- a custom preview Blade component
- extra CSS variables or data attributes derived from editor state

The extension receives a `ThemeEditorContextData` instance so it can decide whether
it supports the current theme. Keep editor-only values under the clean editor state
shape:

- `meta.editor.preset.active`
- `meta.editor.brand.*`
- `meta.editor.header.*`
- `meta.editor.surface.*`
- `meta.editor.footer.*`
- `meta.editor.assets.*`
- `meta.editor.advanced.*`
- `admin.editor.*`

Do not read old flat `meta` or `admin` editor fields from new extension code.
Legacy fields remain compatibility data only.

```php
use Capell\Admin\Contracts\Themes\ThemeEditorExtension;
use Capell\Admin\Data\Themes\ThemeEditorContextData;
use Capell\Admin\Data\Themes\ThemeEditorStateData;

final class AgencyLaunchThemeEditorExtension implements ThemeEditorExtension
{
    public function supports(ThemeEditorContextData $context): bool
    {
        return $context->themeKey === 'agency-launch';
    }

    public function editorSections(ThemeEditorContextData $context): array
    {
        return [];
    }

    public function samplePreviewContent(ThemeEditorContextData $context): array
    {
        return [
            'headline' => 'Launch campaigns with reusable sections.',
            'body' => 'Preview copy should show the package sections without querying public models.',
        ];
    }

    public function previewComponent(ThemeEditorContextData $context): ?string
    {
        return null;
    }

    public function cssVariables(ThemeEditorStateData $state, ThemeEditorContextData $context): array
    {
        return [
            '--agency-launch-accent' => $state->brand['accentColor'] ?? '#f59e0b',
        ];
    }

    public function dataAttributes(ThemeEditorStateData $state, ThemeEditorContextData $context): array
    {
        return [
            'theme-package' => $context->themeKey,
        ];
    }
}
```

Register it from the package service provider:

```php
$this->app->tag(AgencyLaunchThemeEditorExtension::class, ThemeEditorExtension::TAG);
```

## Public Theme Views

The service provider makes the package page wrapper and views available. The
frontend runtime resolves the installed theme through `ThemeRegistry`, builds
hydrated public render data, and sends it through the configured frontend response
pipeline. Public Blade views receive that data; they do not query models or
resolve theme state themselves.

`FrontendServiceProvider` registers a `RenderHookLocation::HeadClose` hook. When
the active theme definition and token stylesheet are available, the hook resolves
the active preset through `ResolveThemeRuntimeAction` and emits the sanitized
theme-token stylesheet. Package views should not recreate that token output.

Do not print authoring metadata. Public HTML must be safe for anonymous visitors, signed-in users, admins, crawlers, cache files, and static exports.

## Assets

Register package asset sources from PHP:

- Tailwind source paths through `TailwindAssetsRegistry`
- CSS/JS dependencies through the package provider or the package's frontend asset registration code
- token-compatible CSS through `ThemeTokenStore` output, not hard-coded critical CSS paths

In public theme Blade, use `@frontendAsset('path/from/public.css')` for assets
served from the host app's `public/` directory. Avoid root-relative `src="/..."`
or `href="/..."` in theme views because the frontend head may include a `<base>`
tag for the configured site domain.

The current direction is to use the optional frontend optimizer package for generated critical CSS. Do not add a theme-level Beasties/Critters fallback.

## Diagnostics And Tests

Run the authoring validation command in the app:

```bash
php artisan capell:themes:validate agency-launch
php artisan capell:theme:doctor agency-launch --path=packages/theme-agency-launch
```

Package tests should cover:

- `capell.json` and Composer metadata are valid
- `ExtensionTestHarness::assertThemeManifest()` passes for the expected theme key
- `ExtensionTestHarness::assertThemeUsesSafeAssetUrls()` passes
- provider registers the expected `ThemeDefinitionData` with `ThemeRegistry`
- all advertised presets resolve
- preview images are present in the package/public asset path
- public rendered output contains expected frontend HTML
- public rendered output does not expose authoring metadata

Use a route-backed frontend test for at least one seeded page. It proves package
boot, view registration, asset planning, cache behavior, and public safety
together.
