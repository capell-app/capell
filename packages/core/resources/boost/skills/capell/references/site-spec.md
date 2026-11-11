# Capell SiteSpec Reference

SiteSpec is Capell's deterministic site handoff contract. Core validates and imports it without making creative decisions. AI and other generators may produce the JSON, but they live outside core and must emit this contract exactly.

## Contents

- [Import](#import)
- [Contract](#contract)
- [Apply to an existing site](#apply-to-an-existing-site)
- [Presets](#presets)
- [Remote Media Safety](#remote-media-safety)
- [Package-Owned Blocks](#package-owned-blocks)

## Import

```bash
php artisan capell:site-spec-import storage/app/site-spec.json
```

The path may be absolute or relative to the application working directory. The importer hashes the normalized contract; importing the same spec again returns the existing site instead of duplicating it.

## Apply to an existing site

```bash
php artisan capell:site-spec-import storage/app/site-spec.json --plan --site=3
php artisan capell:site-spec-import storage/app/site-spec.json --site=3
```

`--site` applies the spec to a site that already exists instead of creating a new one. Pages are matched by slug in the spec's language (a page's own `meta.slug` wins over its spec slug): missing pages are created, pages whose name, title or content differ are updated, and the rest are left alone. Two pages on the site with the same slug make the apply refuse rather than guess. Theme settings are merged into the site's theme when the theme key matches. Package-owned parts such as navigation run their appliers, and media is attached, once for each distinct spec, and media already attached is not attached again. Navigation appliers replace their data, so anything added by hand to it is replaced when a changed spec is applied.

The site keeps the hash of its original import in `site_spec_hash`, which is how a repeated import finds it and how a privacy repair works, and records the hash of the spec last applied in `site_spec_applied_hash`.

`--plan` prints what the apply would create, update and leave alone, and writes nothing. It makes the same checks as the apply, so a plan that succeeds can be applied. Without `--site` it plans against the site this exact spec was last applied to or imported as, or reports every page as a create. Package-owned parts are listed but not previewed.

Applying never changes whether an existing page is published. A private spec also leaves any page that is live or scheduled untouched, and with it package-owned parts and media, because its copy and images must not reach a live site; the plan marks such pages as held. Pages a private spec adds are created as drafts. A public, acknowledged spec updates live pages.

## Contract

```json
{
    "site": {
        "name": "Harbour Books",
        "businessName": "Harbour Books Ltd",
        "organisationType": "bookshop"
    },
    "theme": {
        "key": "default",
        "colors": {
            "primary": "#123456"
        }
    },
    "language": {
        "code": "en",
        "name": "English",
        "locale": "en_GB",
        "flag": "gb",
        "default": true
    },
    "pages": [
        {
            "name": "Home",
            "slug": "home",
            "title": "Welcome",
            "pageType": "default",
            "order": 0,
            "sections": [
                {
                    "type": "content",
                    "content": "<p>Independent booksellers.</p>",
                    "order": 0
                }
            ]
        }
    ],
    "navigations": [
        {
            "key": "main",
            "name": "Main navigation",
            "pageSlugs": ["home"]
        }
    ],
    "media": {
        "sourceUrl": "https://www.example.com",
        "logo": "https://www.example.com/assets/logo.png",
        "images": {
            "home": "https://www.example.com/assets/home.jpg"
        }
    },
    "extensions": ["capell-app/navigation"],
    "initialVisibility": "private",
    "acknowledgePublic": false
}
```

`site`, `theme`, and at least one `pages` entry are required. Page slugs and navigation keys use lowercase kebab-case. Navigation page references and media image keys must match page slugs in the same spec. Extension values are Composer package names and every requested package must already be installed before import starts.

Private imports publish only a neutral, noindex coming-soon homepage. A public import requires both `"initialVisibility": "public"` and `"acknowledgePublic": true`.

## Presets

Core ships three starting points as SiteSpec files under `packages/core/resources/site-spec/presets/`: `service-business`, `shop-front` and `studio`. Each is a complete, private, five-page draft with real copy and a main navigation, and passes the same validation as any other spec. Read them through `SiteSpecPresetRegistry` (`all()`, `get($key)`, `keys()`).

A theme or package adds its own preset by tagging a class that implements `SiteSpecPresetProvider` with `SiteSpecPresetProvider::TAG` and returning `SiteSpecPresetData` from `presets()`. Preset keys are unique across Core and every provider; a duplicate key throws when it is registered.

## Remote Media Safety

- `media.sourceUrl` declares the one allowed HTTPS origin for every logo and page image.
- DNS resolution must return only public addresses. The importer pins the validated address for the request, refuses redirects, and blocks private or reserved destinations.
- Images are limited to GIF, JPEG, PNG, and WebP. Response and detected MIME types must agree.
- Limits are 5 MB per file, 25 MB for the spec, and 15 page images. Failed imports clean up temporary downloads.
- Persisted media metadata records only the source origin and a URL hash; query strings and signed source URLs are not stored.

## Package-Owned Blocks

Core cannot depend on models from optional packages such as navigation. A package applies its own SiteSpec block by implementing the stable contract:

```php
<?php

declare(strict_types=1);

namespace Vendor\Package\SiteSpec;

use Capell\Core\Contracts\SiteSpec\SiteSpecApplier;
use Capell\Core\Data\SiteSpec\CapellSiteSpecData;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;

final class NavigationSiteSpecApplier implements SiteSpecApplier
{
    public function key(): string
    {
        return 'navigation';
    }

    /** @param array<string, Page> $pagesBySlug */
    public function apply(CapellSiteSpecData $spec, Site $site, array $pagesBySlug): void
    {
        // Create or update package-owned navigation records from
        // $spec->navigations and the already-persisted page map.
    }
}
```

Register the implementation with Laravel's container tag in the package service provider:

```php
$this->app->tag(
    [\Vendor\Package\SiteSpec\NavigationSiteSpecApplier::class],
    \Capell\Core\Contracts\SiteSpec\SiteSpecApplier::TAG,
);
```

Appliers run inside the same database transaction as site and page creation. Throwing stops and rolls back the import. Use one applier per key and keep it deterministic; provider calls, prompting, research, and package installation do not belong in an applier.
