# Multi-site and Multi-lingual

![Capell Multi-site and Multi-lingual screenshot](./images/screenshots/core-page-structure.png)

Capell can run multiple websites and multiple languages from a single installation. Both features are built into the core and work together: each site can have its own set of languages, and every piece of content is translatable.

---

## Overview

A **site** in Capell is a distinct website with its own pages, navigation, theme, and settings. You might use multiple sites to manage:

- Separate domains for different brands or regions (`brand-a.com`, `brand-b.com`)
- A main site and a subdomain (`example.com`, `shop.example.com`)
- Path-based site partitions (`example.com/site-a`, `example.com/site-b`)

A **language** defines a locale (e.g. `en`, `fr`, `de`) with its own URL prefix or domain, and its own translations for all page content, navigation, and metadata.

---

## Setting Up Multiple Sites

### Create a site

1. In the admin panel, go to **Settings → Sites**.
2. Click **New Site** and fill in the name, domain, and theme.
3. Configure the site's default language.
4. Create pages and navigation specific to that site.

### Site detection

Capell detects which site a request belongs to by matching the incoming domain against the site's configured domains. Two strategies are supported:

- **Domain-based:** `example.com` → Site A, `another-site.com` → Site B
- **Path-prefix-based:** `example.com/site-a` → Site A, `example.com/site-b` → Site B

You can mix both strategies across sites in the same installation.

### Default site redirect

When a request comes in for an unknown domain, Capell can redirect it to the default enabled site. This behaviour is controlled by:

```php
// config/capell-frontend.php
'redirect_default_site' => true,
```

---

## Setting Up Multiple Languages

### Add a language

1. Go to **Settings → Languages**.
2. Add a language with its code (e.g. `en`), locale (e.g. `en_GB`), and flag.
3. Assign the language to one or more sites.

### Language detection

Capell resolves the language from the request URL. Supported strategies:

- **Subdomain:** `en.example.com`, `fr.example.com`
- **Path prefix:** `example.com/en`, `example.com/fr`

### Translating content

Every page, navigation item, and media file has a translation for each enabled language. To translate a page:

1. Open the page in the admin editor.
2. Use the language switcher to change to the target language.
3. Fill in the translated title, slug, and body.
4. Publish the translation independently (a page can be live in English but still in draft for French).

---

## URL Structure

Capell builds hierarchical URLs from the page tree, using each page's slug for the active language:

| Pattern                        | Example URL                   | Description                       |
| ------------------------------ | ----------------------------- | --------------------------------- |
| Default site, default language | `example.com/about/team`      | No prefix                         |
| Path-prefixed language         | `example.com/fr/about/equipe` | Language prefix + localized slugs |
| Subdomain language             | `fr.example.com/about/equipe` | Subdomain + localized slugs       |
| Separate domain, site B        | `autre-site.com/contact`      | Different domain                  |

When a parent page's slug changes in any language, Capell automatically rebuilds all descendant slugs for that language and records the old paths as 301 redirects.

---

## SEO

### Hreflang tags

Capell generates `hreflang` link elements for every published language variant of a page. These are included in the `<head>` automatically and tell search engines which language version to show to which users.

### Language-aware sitemaps

Each site+language combination gets its own XML sitemap entry. Sitemaps include `<xhtml:link>` alternate entries pointing to the other language variants of each page.

### Canonical URLs

The canonical URL for each page is generated based on the active site domain and language. If a page appears under multiple paths (e.g. after a URL change), only the current canonical is included.

---

## Performance

- Each site+language combination is cached separately. Changing an English page does not purge French cache entries.
- Language and site resolution happens at the very beginning of the request pipeline (the `SiteResolveStep`). All subsequent processing — page resolution, layout loading, caching — is scoped to the resolved site and language.
- Translations are loaded lazily only when the relevant language context is active.

---

## Further Reading

- [Page Management](page-management.md) — URL history, redirects, slug management
- [Content Management](content-management.md) — translating content, per-language publishing
- [Page & Site Loading](../../frontend/docs/page-site-loading.md) — how site/language resolution works internally
- [Sitemaps](https://docs.capell.app/sitemaps/) — XML sitemap generation and serving (in the `capell-app/site-discovery` add-on)
- [Packages and extensions](../../../docs/packages/catalog.md) — host package boundaries and extension documentation entry points
