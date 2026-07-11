# Content Sections

![Capell Content Sections screenshot](../images/generated/admin/first-page-content-editor.png)

> **Heads up:** Content Sections is an approved, optional **Capell Foundation** package. It is a Composer package you add to an app, not core host behaviour. Install it only when a site needs ready-made page sections.

Content Sections ships around seventeen themeable page sections an editor can drop onto a page, with admin management and safe, package-owned rendering. It provides:

- Ready-made sections such as hero, FAQ, pricing, testimonials, team, timeline, comparison, and stats.
- A section selector and admin management for arranging and configuring sections.
- Safe package-owned Blade rendering that keeps editor internals out of public HTML.

## Install

Content Sections requires [Layout Builder](layout-builder.md), which Composer pulls in automatically:

```bash
composer require capell-app/content-sections
php artisan migrate
```

Full documentation lives at `https://docs.capell.app/packages/content-sections`.

## Next

- [Layout Builder](layout-builder.md) — the composition engine Content Sections builds on.
- [Packages and extensions](catalog.md) — host package boundaries and the full add-on catalogue.
- [Packages](README.md) — package authoring and extension points.
