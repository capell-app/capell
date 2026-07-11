# Page Management

Pages are the core building block of a Capell site. This document explains how the page hierarchy works, how URLs are built and maintained, the publishing workflow, and how to use page blueprints.

---

## The Page Hierarchy

Capell stores pages in a tree structure with unlimited depth. A typical site might look like this:

```
Home
├── About
│   ├── Our Team
│   └── Our Story
├── Services
│   ├── Consulting
│   └── Development
└── Contact
```

Every page can have a parent, and every parent can have any number of children. The hierarchy is stored using a nested set model, which makes querying subtrees (e.g. "all descendants of Services") efficient.

![Capell pages list showing the page hierarchy](images/screenshots/core-page-structure.png)

### Creating child pages

In the admin panel, when editing or creating a page, set the **Parent** field to place the page within the hierarchy. Pages can be reordered and reparented via drag-and-drop in the page list.

---

## URL Management

### How URLs are built

Each page has a **slug** — a URL-friendly version of its name. Capell builds the full URL path by combining slugs from the page's ancestry:

```
/services/consulting
 ↑         ↑
 slug of   slug of child
 parent    page
```

When using multiple languages, each page stores a separate slug per locale, and the URL is built from the localized slugs of all ancestors.

### Automatic URL updates

When you change a parent page's slug, Capell automatically rebuilds the URLs of all descendant pages. You do not need to update child pages manually.

### URL history and redirects

When a page's URL changes (because its own slug or a parent's slug changed), Capell records the old URL and sets up an automatic 301 redirect. This prevents broken links and preserves search engine rankings.

Page URLs can also be created with the **Redirect** type. Use this when a new page replaces an existing public URL: create the page at its new canonical location, then add a redirect Page URL for the old path. The old URL stays in the Page URL table as a redirect record instead of requiring a hidden duplicate page or a one-off route.

This is the preferred workflow for migrations and content consolidation:

1. Create the replacement page with the correct site, language, parent, slug, blueprint, and layout.
2. Confirm the page's current URL is the canonical destination.
3. Add a Page URL with type **Redirect** for the old path, such as `/old-services`.
4. Set the redirect target to the new page URL, such as `/services`.
5. Use a permanent redirect when the old URL should be retired for search engines and inbound links.

Redirect Page URLs are site and language scoped, so add one per language or site variant that had a public legacy URL. The source path must be a path such as `/old-services`; absolute external URLs belong in the redirect target when you are sending traffic to another domain.

Manual redirects are stored as page URL records too. To redirect every request on a site/language to another domain while keeping normal domain resolution, create a manual redirect with source `/*` and an absolute target URL such as `https://example.com`. Capell appends the requested path and query string, so `/products/widget?ref=old` redirects to `https://example.com/products/widget?ref=old`.

---

## Publishing Workflow

### Draft and published states

Every page exists in one of two states:

- **Draft** — visible only in the admin preview; not served on the frontend; not included in the static HTML cache or sitemaps.
- **Published** — live on the frontend, included in the cache and sitemaps.

Changes to a published page create a new draft version. Publishing the draft updates the live version and triggers a cache purge for that page.

### Scheduled publishing

You can set a future **Publish Date** on a page. Capell will hold the page in draft state and publish it automatically when the date arrives (requires a running queue worker).

### Draft previews

While a page has a [Publishing Studio](../../admin/docs/permissions-and-approval.md) draft, that persisted workflow draft can be previewed through its own signed draft URL. This is separate from Peek previews: Peek creates a private, signed, short-lived snapshot of the current unsaved admin form state and does not create or imply a draft. The page edit header can expose both saved public output as `Live page` and temporary unsaved output as `Changes` when the Peek package is installed.

### Editor locks

When an editor opens a page in the admin panel, Capell creates a timed content lock for that page. If another editor opens the same page while the lock is active, they see a warning naming the current editor. Saves from another editor are blocked until the lock expires, preventing stale admin forms from overwriting newer changes.

Locks are stored in the `content_locks` table with the owning user, model identity, and `expires_at` timestamp. Opening or saving the page as the owning editor renews the lock; expired locks are automatically replaced by the next editor who opens the page.

---

## Page Blueprints

Blueprints shape how content is edited, rendered, and reused. For pages, a blueprint can change the admin fields, frontend component, cache behaviour, URL accessibility, listing behaviour, sitemap inclusion, and layout-editing options available to that page.

Capell ships with a standard page blueprint, and you can add custom blueprints for specialised content. A product page could require a price field and use a product detail component. A team member page could require a photo and bio. A campaign page could use a landing-page component, opt out of archive listings, and keep its layout locked.

Application and package code uses `Blueprint` records and `blueprint` relationships.

### Choosing a page blueprint

When creating a page, select its blueprint from the dropdown. The blueprint determines which editing rules and frontend behaviour apply to that page.

### Registering custom page blueprints

See [Blueprints](../../../docs/getting-started/types.md) for product examples, and [Extending Capell](extending-capell.md#2-page-types-and-component-registration) for how to register page subject blueprints from your application or a package.

---

## SEO Settings

SEO authoring and audit UI is provided by SEO Suite. When that package is installed, page editors get SEO metadata fields, canonical controls, robots directives, preview/report panels, and audit widgets through package-owned admin extenders.

Core page rendering still supports multilingual URL and canonical relationships, but the user-facing SEO workflow lives in the SEO Suite package.

---

## 404 Error Pages

You can define a custom 404 page for each site. When Capell cannot resolve a URL to a page, it resolves the configured `error` system page through the frontend system-page resolver. The default resolver uses the site's System Page layout: minimal chrome, a logo or site-name link back to the home page, and only the page title/content.

Packages can replace or extend system page resolution by tagging an implementation of `Capell\Frontend\Contracts\SystemPageResolver` with `SystemPageResolver::TAG`. This keeps 404 handling out of controller logic and lets other system pages, such as maintenance pages, share the same extension point.

The frontend package owns maintenance rendering, static maintenance middleware, and the manifest. Optional packages can register a `StaticMaintenancePageStore`; the HTML Cache package contributes one backed by its `page_cache` disk. If no static store is registered, maintenance falls back to Laravel's plain 503.

### Missing URL reports

The admin panel includes a **Missing URLs** report that logs 404 responses with their referring pages. Use this to identify and fix broken links.

---

## Insights and Visitor Data

Capell collects basic visitor page view data. You can view this from the admin panel under each page's **Insights** tab.

> **Note:** This is lightweight built-in tracking. For advanced insights, integrate a third-party service (e.g. Plausible, Fathom, GA4 Reports) via your site's layout or a custom widget.

---

## Linked Pages

A page can be **linked** to another page across sites. This is useful when you have multiple sites that share common pages (e.g. a shared legal pages site). The linked page inherits the target page's content and URL from the originating site.

---

## Further Reading

- [Content Management](content-management.md) — content types, widgets, translations, media
- [Multi-site & Multi-lingual](multi-site-multi-lingual.md) — per-locale URLs, hreflang
- [Extending Capell](extending-capell.md) — custom page subjects and blueprints
- [Packages & Add-ons](../../../docs/packages/catalog.md) — Blog article blueprint and package-owned content
- [Artisan Commands](../../../docs/development/artisan-commands.md) — host commands and optional package command ownership
