# Tailwind Assets Registry & Generator

![Capell Tailwind Assets Registry & Generator screenshot](./images/screenshots/frontend-settings.png)

This document explains how Tailwind assets are collected across Capell packages/providers and how the generator produces a single CSS directive file used by Tailwind.

## Overview

Capell aggregates Tailwind asset declarations from multiple sources so your Tailwind build has a unified set of inputs:

- Imports: `@import "..."` statements for base styles.
- Plugins: `@plugin "..."` directives to enable Tailwind plugins.
- Sources: `@source "..."` globs pointing to Blade/HTML/JS files Tailwind should scan.

Why it’s needed:

- Packages and plugins may define their own Tailwind inputs.
- A central registry ensures de-duplication, stable ordering, and a single generated file consumed by Tailwind.

## Key Components

- `Capell\Core\Support\Tailwind\TailwindAssetsRegistry`: a small in-memory registry capturing imports, plugins, and sources with their origin. It exposes unique, sorted values and a diagnostic report.
- `Capell\Frontend\Support\Tailwind\TailwindAssetsGenerator`: aggregates inputs from config, installed packages, and service providers that implement `RegistersTailwindAssets`, then writes `resources/css/capell/frontend.css`.

## Configuration (Frontend)

Configure defaults under `packages/frontend/config/capell-frontend.php`:

```php
return [
    'tailwind' => [
        'imports' => [
            // e.g. tailwind base imports
            'tailwindcss/base',
            'tailwindcss/components',
        ],
        'plugins' => [
            // e.g. '@tailwindcss/form-builder'
            '@tailwindcss/form-builder',
        ],
        'sources' => [
            // Globs relative to the Frontend package
            'resources/views/**/*.blade.php',
            'resources/js/**/*.ts',
        ],

        // Optional: enable source glob validation and warnings during generation
        'validate_sources' => false,
        // Output CSS file path; absolute or relative, but it must resolve inside
        // the Laravel project. If starting with 'resources/', it resolves under
        // Laravel's resource_path().
        'output_css' => 'resources/css/capell/frontend.css',
    ],
];
```

- By default, the generator writes to your app's `resources/css/capell/frontend.css`.
- Custom output paths must resolve inside the Laravel project root; traversal or absolute paths outside the project are rejected before any file is written.
- If omitted, it falls back to the Frontend package path under `packages/frontend/resources/css/generated/`.

## Import Path Resolution

- Node module imports (e.g., `tippy.js/dist/tippy.css`, `@tailwindcss/form-builder`) are kept as-is in the generated file.
- Package-relative imports and sources (e.g., `resources/css/<package>.css`, `resources/views/**/*.blade.php`) are resolved against their package path and then converted to be relative to the generated file directory. This ensures consistent builds when your output path is under your app resources.

## Output Path Modes

- App mode: when `tailwind.output_css` points under `resources/`, imports and sources from packages are resolved relative to `vendor/{package}`.
- Package dev mode: when output is inside a package (e.g., `packages/frontend/resources/...`), imports and sources are resolved relative to the package path.

### Example (App mode: `resources/css/capell/frontend.css`)

```css
@import 'tippy.js/dist/tippy.css';
@plugin "@tailwindcss/typography";
@source "../../../vendor/capell-app/admin/resources/views/**/*.blade.php";
@source "../../../vendor/capell-app/frontend/resources/views/**/*.blade.php";
```

Each Capell-approved package contributing styles or scanned views adds its own `@import` and `@source` lines automatically. The generated file ends up listing every installed package; installs with Blog, themes, and other add-ons will see additional lines for each. See [Approved packages](../../../docs/packages/catalog.md) for the current package registry.

### Example (Package dev mode: output within a package)

Paths will be relative to the package’s `resources/css/generated` directory and may include traversals into sibling packages if working in a monorepo.

## How Generation Works

1. The generator resolves the output path from `tailwind.output_css`.
2. It aggregates entries from config, installed packages, and providers implementing `RegistersTailwindAssets`.
3. It renders `@import`, `@plugin`, and `@source` lines and writes the file.
4. If `validate_sources` is enabled, it checks each `@source` glob and logs warnings when no files match.

## Programmatic Registration

Beyond static configuration, packages and service providers can register Tailwind assets programmatically via `TailwindAssetsRegistry`. This is useful when your assets depend on runtime conditions, installed packages, or dynamic configuration.

The registry is container-bound and accessed in your service provider's `boot()` method:

```php
namespace MyPackage;

use Capell\Core\Support\Tailwind\TailwindAssetsRegistry;
use Illuminate\Support\ServiceProvider;

class MyPackageServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $registry = app(TailwindAssetsRegistry::class);

        $registry->registerSource(
            'resources/views/**/*.blade.php',
            'my-package'
        );

        $registry->registerImports([
            '@my-package/styles.css',
            'some-npm-module/dist/styles.css',
        ], 'my-package');

        if (config('my-package.enable-theme')) {
            $registry->registerThemeColors([
                'brand-primary' => '#0066cc',
                'brand-secondary' => '#ff6600',
            ], 'my-package');
        }
    }
}
```

### Registry Methods

| Method                  | Signature                                                                       | Behavior                                                                                                                       |
| ----------------------- | ------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------ |
| `registerSource()`      | `registerSource(string $source, ?string $origin = null): self`                  | Register a single `@source` glob. Strings are trimmed; empty strings ignored.                                                  |
| `registerSources()`     | `registerSources(array $sources, ?string $origin = null): self`                 | Register multiple sources at once. Convenience wrapper.                                                                        |
| `registerImport()`      | `registerImport(string $import, ?string $origin = null): self`                  | Register a single `@import` statement. Strings are trimmed; empty strings ignored.                                             |
| `registerImports()`     | `registerImports(array $imports, ?string $origin = null): self`                 | Register multiple imports at once. Convenience wrapper.                                                                        |
| `registerPlugin()`      | `registerPlugin(string $plugin, ?string $origin = null): self`                  | Register a single `@plugin` directive. Strings are trimmed; empty strings ignored.                                             |
| `registerPlugins()`     | `registerPlugins(array $plugins, ?string $origin = null): self`                 | Register multiple plugins at once. Convenience wrapper.                                                                        |
| `registerThemeColor()`  | `registerThemeColor(string $name, string $value, ?string $origin = null): self` | Register a theme color by name. Both name and value trimmed; empty strings ignored. Later registrations override earlier ones. |
| `registerThemeColors()` | `registerThemeColors(array $colors, ?string $origin = null): self`              | Register multiple theme colors at once. Keys are color names, values are colors.                                               |
| `sources()`             | `sources(): Collection<int, string>`                                            | Get unique, sorted sources as a collection of strings.                                                                         |
| `imports()`             | `imports(): Collection<int, string>`                                            | Get unique, sorted imports as a collection of strings.                                                                         |
| `plugins()`             | `plugins(): Collection<int, string>`                                            | Get unique, sorted plugins as a collection of strings.                                                                         |
| `themeColors()`         | `themeColors(): Collection<string, string>`                                     | Get theme colors as a collection keyed by color name, sorted by name.                                                          |
| `hasThemeColors()`      | `hasThemeColors(): bool`                                                        | Whether any theme colors are registered.                                                                                       |
| `toReport()`            | `toReport(): array`                                                             | Return a diagnostic report with all entries (including origin metadata) for debugging.                                         |

All `register*` methods are **fluent** — they return `$this` for method chaining. The `$origin` parameter is optional and used for tracing registration sources in diagnostic reports.

**De-duplication rules:**

- `sources`, `imports`, and `plugins` are de-duplicated by string value; the first registration wins. Results are sorted lexicographically.
- `themeColors` are keyed by color name; later registrations override earlier ones.

## Using the Generated File

Import the generated file from your main Tailwind input (e.g., `resources/css/app.css` or your package's frontend CSS):

```css
@import './capell/frontend.css';

@tailwind base;
@tailwind components;
@tailwind utilities;
```

## CLI Usage

```sh
php artisan capell:frontend-tailwind-assets
```

Diagnostics-only (no write):

```sh
php artisan capell:frontend-tailwind-assets --report
```

This prints a JSON report with `imports`, `plugins`, and `sources`, each entry including `value` and `origin`.

## CI Tips

- Run the generator before your Tailwind build to keep `capell/frontend.css` fresh.
- Consider disabling `validate_sources` in CI if globs are large or vendor paths are absent.

## Troubleshooting

- Missing classes: ensure your `@source` glob is present and correctly resolved relative to the output directory.
- Validation warnings: confirm glob correctness and existence of matched files.
- Duplicates: entries are de-duplicated across all sources.

## Package Author Guidance

- Use `tailwindImports` for CSS import specifiers; node module imports are preserved.
- Use `tailwindSources` for globs; relative paths will be resolved and relativized against the output directory.
- If `tailwindSources` is empty, a default `resources/views/**/*.blade.php` glob is added for your package.

## Conditional Vendor Assets

Use `VendorAssetConditionRegistry` when a package build asset should only load for a matching frontend request. Register the condition during package boot, then pass the condition name to `VendorAssetData::buildAsset()`.

```php
use Capell\Core\Data\VendorAssetData;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Assets\VendorAssetConditionRegistry;

app(VendorAssetConditionRegistry::class)->register(
    'vendor.theme.carousel',
    fn (): bool => $this->currentPageUsesCarousel(),
);

CapellCore::registerVendorAsset(
    VendorAssetData::buildAsset(
        path: 'vendor/theme/carousel',
        file: 'resources/js/carousel.js',
        packageName: 'vendor/theme',
        condition: 'vendor.theme.carousel',
    ),
);
```

Unconditional assets keep loading normally. Conditional assets with no registered condition do not load.
