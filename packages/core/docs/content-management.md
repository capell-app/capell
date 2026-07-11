# Content Management

![Capell Content Management screenshot](./images/screenshots/core-page-structure.png)

Capell separates _pages_ (structure and URLs) from _content_ (the actual data displayed on pages). This separation, combined with a flexible content type system, lets you reuse and compose content across your site without duplication.

---

## Pages vs Content

A **page** defines a URL, its place in the hierarchy, and which layout to use. A **content item** is the reusable data attached to that page — a body of text, an image block, a call-to-action, or any custom structure you define.

This matters because the same content item can appear in multiple places. For example, a "Featured Services" listing can pull from a shared set of content items that are managed in one place, even if they appear on several pages.

---

## Content Types

Content blueprints define the fields available on a content item. Capell ships with a standard rich-text type. You can register custom blueprints for structured data (see [Extending Capell](extending-capell.md)).

---

## Translations

All content in Capell is translatable. Every translatable field (title, body, slug, media alt text, navigation labels) stores a separate value per language.

### Editing translations

When editing a page or content item in the admin, a **language switcher** lets you toggle between languages. Each language has its own set of field values; they do not share data unless you choose to copy a value.

### Translation status

The admin shows which languages have been translated for each page or content item, and which are still using the default language values.

### Publishing per language

You can publish each language version of a page independently. A page can be published in English but still in draft for French, allowing incremental rollout of translations.

---

## Media Management

Capell uses [Spatie Media Library](https://spatie.be/docs/laravel-medialibrary) for media management.

### Media library

Upload and manage images, documents, and other files from within the admin panel. Each media item has translatable metadata stored in the shared `translations.meta` JSON column, including alt text, caption, credit, and whether the image is decorative.

### Image optimisation

Images are automatically resized and compressed when attached to pages or content items. Conversions are defined per model and can be customised for your site's needs.

### Cropping and focal points

When Curator is installed, Capell lets Curator own image cropping. Without Curator, the Spatie-backed media edit page includes focal-point controls and crop presets for common aspect ratios.

### Media usage

When you delete a media item, you can see which pages or content items reference it, helping you avoid broken images.

---

## Tags and Categories

If the [Blog package](../../../docs/packages.md#blog) is installed, article pages support tagging via Spatie Tags. Tags are managed in the admin under their own resource and can be applied to multiple articles.

---

## Navigation

Navigation menus are managed per site and per language. Each menu has a list of items that can point to internal pages (resolved by ID, so URL changes are handled automatically) or external URLs.

### Auto navigation helpers

From Blade templates, Capell provides helpers to fetch navigation-relative items in the context of the current page and language:

- Children and descendants of the current page
- Sibling pages
- Next and previous pages in the hierarchy
- Ancestor pages (for breadcrumbs)

These helpers are cache-aware and respect site and language context.

---

## Frontend Authoring

In-page editing is optional package behavior, not Core content output. Frontend Authoring loads after the public page has loaded, calls an authenticated admin-only beacon, and then decorates the page for users who can edit it.

Anonymous users, signed-in non-admin users, cached HTML, and static exports must receive ordinary public HTML with no edit links, authoring scripts, model IDs, field paths, selectors, permissions, package names, or signed admin URLs. If those details appear in public Blade or cached HTML, treat it as a rendering bug.

---

## Further Reading

- [Page Management](page-management.md) — hierarchy, URLs, publishing, draft previews
- [Multi-site & Multi-lingual](multi-site-multi-lingual.md) — per-locale content and URLs
- [Packages & Add-ons](../../../docs/packages.md) — Blog, Hero
- [Extending Capell](extending-capell.md) — custom content types
- [Render Hooks](../../frontend/docs/extending-render-hooks.md) — injecting UI into frontend components
