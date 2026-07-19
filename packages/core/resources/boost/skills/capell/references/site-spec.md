# Capell SiteSpec Reference

SiteSpec is Capell's deterministic site handoff contract. Core validates and imports it without making creative decisions. AI and other generators may produce the JSON, but they live outside core and must emit this contract exactly.

## Import

```bash
php artisan capell:site-spec-import storage/app/site-spec.json
```

The path may be absolute or relative to the application working directory. The importer hashes the normalized contract; importing the same spec again returns the existing site instead of duplicating it.

## Contract

```json
{
    "site": {
        "name": "Harbour Books",
        "businessName": "Harbour Books Ltd",
        "organisationType": "bookshop"
    },
    "theme": {
        "key": "foundation",
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

## Remote Media Safety

- `media.sourceUrl` declares the one allowed HTTPS origin for every logo and page image.
- DNS resolution must return only public addresses. The importer pins the validated address for the request, refuses redirects, and blocks private or reserved destinations.
- Images are limited to GIF, JPEG, PNG, and WebP. Response and detected MIME types must agree.
- Limits are 5 MB per file, 25 MB for the spec, and 15 page images. Failed imports clean up temporary downloads.
- Persisted media metadata records only the source origin and a URL hash; query strings and signed source URLs are not stored.

## Package-Owned Blocks

Core cannot depend on optional navigation or other companion package models. A package applies its own SiteSpec block by implementing the stable contract:

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
