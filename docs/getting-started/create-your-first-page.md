# Create your first page

This guide walks through creating your first Capell page from the admin panel. It is written for a first-time user who has already installed Capell and can log in to `/admin`.

If you have not installed Capell yet, start with the [quickstart](quickstart.md) or the full [install guide](install.md).

## Start from Pages

Open **Pages** from the sidebar. Pages are the main routable content records in Capell: they belong to a [Site](../reference/glossary.md#editing-terms), can sit inside a page tree, and publish to frontend URLs.

![The Pages list in Capell Admin](../images/generated/admin/first-page-pages-list.png)

Click **New page** in the top-right corner.

![The New page action on the Pages list](../images/generated/admin/first-page-new-page-action.png)

## Choose the page context

The first fields decide where the page lives before you write content.

![The top fields on the create page form](../images/generated/admin/first-page-create-top-fields.png)

**Site** — which public website owns the page. Even on a single-site install, the Site carries the domain, languages, default pages, related sites, and brand-level details the frontend uses.

**Parent Page** — where the page sits in the tree.

- Leave it empty for a top-level page such as `/about`.
- Choose a parent when the page belongs under another, such as `/about/team`.

**Internal name** — the admin-facing name for the page record. It normally follows the page title, but you can keep the admin list clear when the frontend title is long or marketing-led.

![The Site selector on the page form](../images/generated/admin/first-page-site-selector.png)

![The Parent Page selector on the page form](../images/generated/admin/first-page-parent-selector.png)

## Understand parents and URLs

Capell builds URLs from the page tree.

| Page setup                                   | Resulting URL    |
| -------------------------------------------- | ---------------- |
| Top-level page with slug `about`             | `/about`         |
| Child page with slug `team` under `about`    | `/about/team`    |
| Child page with slug `careers` under `about` | `/about/careers` |

Moving a page to a different parent changes the URL path. Capell creates automatic redirect [Page URLs](../../packages/core/docs/page-management.md) when a published page's slug or parent path changes, so old URLs keep pointing at the current page. Use the Redirects or URL Manager package when you need manual redirects, imports, hit counts, or deeper redirect reporting.

For more background, read [How Capell works](how-capell-works.md#the-core-model).

## Add the title and slug

**Title** — the main human-readable page title. It usually appears in the browser title, search snippets, social previews, and any frontend template that prints the page heading.

**Slug** — the URL segment. Capell fills it from the title, so `About Our Team` becomes `about-our-team`. Edit it when you need a shorter or clearer URL.

**URL preview** — shows the Site domain and parent path before the slug. Use it as a quick check before saving.

![The title, slug, and URL preview fields](../images/generated/admin/first-page-title-slug-url.png)

Good first-page examples:

| Page          | Title      | Slug       |
| ------------- | ---------- | ---------- |
| About page    | `About`    | `about`    |
| Contact page  | `Contact`  | `contact`  |
| Services page | `Services` | `services` |

Keep slugs short, lowercase, and stable. Changing a slug after publishing changes the public URL.

## Write the content

**Content** — the main body of the page.

![The page content editor](../images/generated/admin/first-page-content-editor.png)

On a plain install, this is a rich text editor for headings, paragraphs, links, tables, lists, and simple formatting. When the page needs typed blocks or approved section composition, choose the supported path in [Build a page](building-pages.md): page-type blocks with a page-specific `content_structure_override`, or the optional Layout Builder package for containers, widgets, and widget assets.

For your first page, keep it simple:

1. Add a short heading or opening sentence.
2. Add one paragraph of useful body copy.
3. Save as draft.
4. Preview the page before publishing.

## Fill useful extra content

Open **Extra Content** when the page needs supporting fields.

![The Extra Content section on the page form](../images/generated/admin/first-page-extra-content.png)

The exact fields can vary by blueprint and installed packages, but the common ideas are:

| Field                | What it is for                                                  |
| -------------------- | --------------------------------------------------------------- |
| Summary              | A short description for cards, listings, and fallback metadata. |
| Label                | Alternate text used by some themes or navigation surfaces.      |
| Link text / CTA text | Button or link copy when another page links to this one.        |

You do not need to fill every field on the first pass. Add the content that helps the frontend theme render the page well.

## Pick a layout and publish timing

**Layout** — which frontend template renders the page. A normal content page can use the default layout; a landing page, article, product page, or campaign page may use a different one if your project has registered it.

**Visible From** — scheduled availability. Leave it empty when the page should be available as soon as it is published.

![The layout and publish timing fields](../images/generated/admin/first-page-layout-publish-fields.png)

Page **Blueprint** is closely related to layout, but it is not the same thing. Blueprints define reusable editing, rendering, and behaviour rules:

| Concept   | Practical meaning                                       |
| --------- | ------------------------------------------------------- |
| Blueprint | Reusable editing, rendering, and behaviour rules.       |
| Layout    | Controls how the frontend renders the page.             |
| Content   | The text, media, and structured fields the editor adds. |

Developers can register custom blueprints through Capell extension points. Read [Blueprints](types.md) for examples and screenshots, or [How Capell works](how-capell-works.md#extension-points) when you are ready for the deeper model.

## Save as draft first

Use **Save as Draft** while you are still editing. A draft is stored in the admin, but it is not the public version yet.

Use **Create** or **Save changes** when you are ready to store the record normally. On a plain install, use **Save and Publish** or **Publish** when the page is ready to go live. If workflow packages are installed, publishing may move through [approvals](../../packages/admin/docs/permissions-and-approval.md), Publishing Studio, or scheduled publishing.

![The create and save as draft actions](../images/generated/admin/first-page-save-actions.png)

For the first page, save a draft, then preview it.

## Preview and publish

Return to the Pages list and open the row action for your page. The preview action opens the frontend view so you can check the content before making it public.

After publishing, visit the page URL directly.

Use **Unpublish** from the edit page when the page should come down. To schedule a removal, set **Visible until** in the Publish Dates section. Use **Cancel scheduled unpublish** if the page should stay live after a removal date was set.

If the frontend still shows old content, use the admin **Clear Cache** action. Ask a developer to run the cache commands below only when the admin action does not clear the stale output:

```bash
php artisan capell:html-cache:clear
```

If the project uses `capell-app/html-cache`, a developer can also run `php artisan capell:static-site` to warm the [generated public cache](../architecture/page-cache.md).

If the project uses a queue connection such as `database` or `redis`, a developer should keep a worker running while testing publishes:

```bash
php artisan queue:work
```

See [Troubleshooting](../operations/troubleshooting.md) if the page shows a 404, stale content, or a blank frontend screen.

## Adjust settings after the page exists

On edit screens, Capell shows additional page settings and package-provided fields. These can include SEO settings, canonical URL choices, cache behaviour, featured images, and other fields added by extensions.

![The edit page settings tab](../images/generated/admin/first-page-edit-settings-tab.png)

For a first page, avoid tuning everything at once. Confirm the page renders first, then come back for SEO and sharing details.

## Configure the Site once

A **Site** is bigger than one page. It represents the public web property that all its pages belong to.

Site-level details can apply across every page for that site:

| Site detail                | Where it is used                                             |
| -------------------------- | ------------------------------------------------------------ |
| Company or business name   | Footer, contact blocks, schema, and theme copy.              |
| Logo and inverted logo     | Header, footer, dark backgrounds, and theme components.      |
| Favicon and icon           | Browser tabs, saved shortcuts, and app-like surfaces.        |
| Brand color                | Theme accents, buttons, and package-aware UI choices.        |
| Email, phone, contact page | Footer, contact cards, schema, and reusable widgets.         |
| Domains and languages      | URL generation, language tabs, canonical links, and routing. |

Site records live in the **Settings** area of the admin sidebar. Open **Sites** when you need to update the company, logo, favicon, brand, domain, language, or related site details behind the pages.

![The Settings area where site-wide configuration is managed](../images/generated/admin/first-page-site-branding-settings.png)

Change Site details when the same value should affect the whole website. Change Page details when the value only belongs to one page.

For the full admin map, see the [Admin interface guide](../admin/interface.md).

## Useful next extensions

Do not install every package on day one. Add the next package when the page you are building clearly needs it.

| Extension                                                      | Add it when you need                                                                                       |
| -------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------- |
| [ContentSections](../packages/catalog.md#capell-foundation)    | Custom rows, columns, widgets, reusable content blocks, and richer page layouts.                           |
| [Navigation](../packages/catalog.md#capell-foundation)         | Header, footer, sidebar, or utility menus that link your pages together.                                   |
| [Redirects](../packages/catalog.md#capell-foundation)          | Manual redirects or safer URL changes after pages have been published.                                     |
| Built-in Default Theme                                         | A practical frontend starting point provided by `capell-app/frontend` without installing a theme package.  |
| [Frontend Authoring](../packages/catalog.md#capell-foundation) | Edit page title, description, or content from the public page after the admin-only beacon confirms access. |
| [Media Library](../packages/catalog.md#capell-foundation)      | The Curator media backend and picker, instead of the default Spatie MediaLibrary backend.                  |
| [SEO Suite](../packages/catalog.md#capell-search--seo)         | Audits, structured data, robots controls, and stronger social metadata.                                    |
| [Site Discovery](../packages/catalog.md#capell-foundation)     | HTML/XML sitemaps and discoverability files.                                                               |

That order keeps the learning curve sane: create pages first, add structured blocks or Layout Builder when the content needs it, connect pages with Navigation, protect URLs with Redirects, then add SEO or richer operational tooling when the site is real enough to benefit.

## Next

- [Your first session](first-session.md) for the broader admin tour.
- [Build a page](building-pages.md) to choose HTML, blocks, Layout Builder, or a dedicated Blade layout.
- [How Capell works](how-capell-works.md) for Sites, Pages, Blueprints, Layouts, and extension points.
- [Packages and extensions](../packages/catalog.md) for host package boundaries and extension documentation links.
- [Glossary](../reference/glossary.md) for quick definitions.
