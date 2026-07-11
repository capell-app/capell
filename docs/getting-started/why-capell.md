# Why Capell

![Capell Why Capell screenshot](../images/capell-readme-banner.jpg)

Capell is for Laravel teams who want a real CMS without moving content, routing, roles, or public delivery into a separate product.

You keep Laravel, and add the CMS layer most teams underestimate.

**What you keep (Laravel)**

- Eloquent models and relationships
- Queues, Blade, and Composer
- Tests and your existing deployment

**What Capell adds**

- Page trees, multi-site setup, and multi-language URLs
- Media contracts, redirects, permissions, and settings
- Frontend delivery, [Marketplace](../../packages/marketplace/docs/overview.md) visibility, and extension points

The host packages in this repository provide the foundation. First-party packages add product features such as visual content sections, in-page authoring, generated HTML cache, site discovery, SEO, Publishing Studio, Migration Assistant, Inertia, and themes.

## The short version

| If you are thinking about...    | Capell gives you...                                                                                            |
| ------------------------------- | -------------------------------------------------------------------------------------------------------------- |
| Building a CMS on Filament      | The page tree, URLs, sites, languages, media, roles, settings, admin surfaces, and package hooks wired         |
| Using a flat-file CMS           | Laravel-native data, normal Eloquent relationships, queues, and database-backed workflows                      |
| Custom-building page blocks     | Package-discovered widgets, [ContentSections](../packages/catalog.md#capell-foundation) surfaces, reusable content, and theme-aware layout areas           |
| Making public pages interactive | Safe trigger markup, encrypted lazy widget/fragment targets, and reusable package-owned interactions           |
| Shipping a multilingual site    | Site-aware languages, translated URLs and fields, media metadata, canonical URL foundations, and package hooks |
| Making pages fast               | Cache-aware rendering, model dependency tracking, ETags, and optional generated HTML/optimizer packages        |
| Letting editors publish safely  | Host publish dates and role access; Publishing Studio adds workspaces, [approvals](../../packages/admin/docs/permissions-and-approval.md), scheduling, and revisions    |

For a non-technical stakeholder, the useful summary is this: Capell gives the team a Laravel-owned place to manage pages, content, media, publishing, and site structure without asking developers to rebuild the same CMS foundations on every project.

## Compared with custom Filament

Filament is a brilliant admin framework. Capell uses it because it is the right foundation. The difference is that Capell solves the CMS product, not just the UI shell.

| Problem            | Custom Filament build                                                                   | Capell approach                                                                            |
| ------------------ | --------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------ |
| Page tree and URLs | Build nested pages, slug generation, redirects, breadcrumbs, and move handling          | Host pages, Page URLs, URL history, redirect records, and move-aware behaviour             |
| Publishing         | Decide publish states, previews, scheduling, queues, and cache invalidation             | Host publish dates and cache hooks; Publishing Studio adds workflow depth                  |
| Multi-site         | Add site scoping to every query, setting, permission, URL, sitemap, and cache key       | Host site/domain/language model plus package hooks for discovery and operations            |
| Multi-language     | Translate fields, slugs, media metadata, navigation, SEO, canonical and alternate links | Host translated records and URLs; packages add sitemap, SEO, and navigation UI             |
| Media              | Pick backend, wire fields, metadata, ownership, and editor UI                           | Host media contracts with a default Spatie backend; Media Library can swap in Curator      |
| SEO                | Add sitemap, Open Graph, Twitter cards, JSON-LD, redirects, robots, and checks          | Host URL/canonical foundations; Site Discovery, URL Manager, and SEO Suite add product SEO |
| Upgrade path       | Every project invents conventions                                                       | Stable Capell extension points and first-party packages                                    |

The senior-developer win is not that Capell hides Laravel. It is that Capell keeps the repeated CMS parts consistent, testable, and replaceable.

## Compared with Statamic

Statamic is a strong CMS, especially when flat-file content is the right model. Capell is a better fit when the site is already a Laravel product or needs application-style workflows.

| Need                    | Statamic-style approach                    | Capell approach                                             |
| ----------------------- | ------------------------------------------ | ----------------------------------------------------------- |
| Laravel app integration | Content often lives beside the app         | Content lives in the app database and uses Laravel services |
| Complex relationships   | Usually needs custom fieldtypes or add-ons | Normal Eloquent models and package relations                |
| Queued publishing       | Project-specific                           | Laravel queues plus Capell static generation                |
| Admin framework         | CMS-specific control panel                 | Filament resources, pages, widgets, and form-builder        |
| Package customization   | CMS add-on model                           | Composer packages with Capell extension points              |

If you mainly need an editorial website with flat files, Statamic can be excellent. If you need a CMS inside a Laravel system with database-backed workflows, Capell fits more naturally.

## Compared with building everything yourself

The danger in custom CMS work is not the first page editor. It is everything around it:

- moving a parent page and rebuilding child URLs;
- creating [redirects](../../packages/core/docs/page-management.md) when slugs change;
- previewing drafts without poisoning the public cache;
- scoping editors to one site inside a multi-site install;
- generating hreflang and canonical tags across languages;
- invalidating only affected pages after publishing;
- giving package authors clean places to add fields, settings, widgets, and [render hooks](../../packages/frontend/docs/extending-render-hooks.md).

Capell turns those into defaults or package-owned extension points. A project can serve anonymous traffic from generated static HTML when the [HTML Cache](../architecture/page-cache.md) package is installed, while Capell tracks which pages used each model and clears affected output after edits.

Site-level changes get special handling too: when a title, theme, metadata, or media setting changes, cached URLs on that site's domains can be purged automatically, including the homepage.

## Where Capell is intentionally flexible

Capell does not force one theme, one media backend, or one content model.

| Customization | How you do it                                                                           |
| ------------- | --------------------------------------------------------------------------------------- |
| Page templates | Register page subject contracts and reusable blueprints                                  |
| Editor fields | Add schema extenders                                                                    |
| Frontend HTML | Use Blade, themes, render hooks, and widgets                                            |
| Blocks        | Register ContentSections widgets and theme chrome areas                                 |
| Settings      | Add package settings schemas                                                            |
| Media backend | Use the default Spatie MediaLibrary backend or [switch to Curator](../frontend/media-rendering.md)                        |
| Cache rules   | Register dependencies and invalidation patterns                                         |
| Import/export | Install the premium Migration Assistant package and extend its recovery/import behavior |

Content Sections is not limited to a single editable page body. Themes can register named layout areas such as `header`, then render those areas from theme chrome while editors keep using ordinary containers and elements. That gives teams editor-managed headers, announcement bars, footers, or campaign strips without custom one-off fields or hidden main-flow containers.

Capell Interactions adds the next layer: editors can attach trigger buttons to widgets or Layout Builder blocks and open lazy widget or fragment targets as modals, slide-overs, inline reveals, or replacement regions. That is useful for video, calculators, form prompts, galleries, pricing comparisons, and optional content that should not weigh down the first page render. Read [Capell Interactions](capell-interactions.md) for the product view.

## Package Groups

Capell Foundation packages stay free for normal CMS needs such as visual building, blog content, navigation, tags, redirects, address fields, media backend swaps, and the default theme.

Premium packages are grouped by the value they unlock: FormBuilder, Publishing Pro, Operations, Growth, Search & SEO, and Themes. See [Package product groups](../packages/product-groups.md) for the current map.

## When not to choose Capell

Capell is probably not the best first choice if:

- you need a tiny brochure site with no editing workflow;
- you do not use Laravel;
- flat-file authoring is a hard requirement;
- you want a fully hosted no-code CMS.

For Laravel teams shipping content-heavy sites, portals, campaign hubs, multi-brand sites, or editor-managed products, Capell gives you a faster start and a cleaner long-term shape.
