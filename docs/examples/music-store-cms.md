# Example: music store CMS

![Capell Example: music store CMS screenshot](../images/admin-pages-list.png)

This walkthrough shows how a real Capell project can be structured. The example is an independent music store with two branches, lessons, repairs, events, and editorial content.

The goal is not to copy these exact names. It is to show how Capell turns a messy content brief into pages, widgets, settings, and workflows.

## The brief

The store needs:

- a homepage for the main brand;
- separate pages for guitars, keyboards, repairs, lessons, and rentals;
- branch-specific opening hours and contact details;
- articles for buying guides and event announcements;
- Spanish translations for key pages;
- editors who can draft seasonal campaigns without publishing too early;
- strong search metadata and structured data.

## Install the useful packages

Start with Capell core, then add the editorial packages:

```bash
composer require capell-app/content-sections capell-app/blog capell-app/address capell-app/site-discovery capell-app/seo-suite
php artisan capell:content-sections-install
php artisan capell:blog-install
php artisan capell:address-install
php artisan capell:seo-suite-install
```

For a demo environment, seed examples:

```bash
php artisan capell:content-sections-demo --sites="Music Store"
php artisan capell:blog-demo --sites="Music Store" --limit=12
php artisan capell:address-demo --sites="Music Store"
```

## Model the sites

Use one Capell installation with multiple sites:

| Site            | Domain or path              | Notes                                    |
| --------------- | --------------------------- | ---------------------------------------- |
| Main store      | `music-store.test`          | Main catalog, lessons, repairs, blog     |
| Downtown branch | `downtown.music-store.test` | Branch-specific landing page and address |
| North branch    | `north.music-store.test`    | Branch-specific landing page and address |

Each site can have its own settings, navigation, theme, language setup, and cache output.

## Build the page tree

Create a page tree like this:

```text
Home
├── Instruments
│   ├── Guitars
│   ├── Keyboards
│   └── Drums
├── Lessons
│   ├── Guitar lessons
│   └── Piano lessons
├── Repairs
├── Rentals
├── Events
└── Contact
```

Capell builds URLs from the tree:

| Page          | URL                      |
| ------------- | ------------------------ |
| Guitars       | `/instruments/guitars`   |
| Piano lessons | `/lessons/piano-lessons` |
| Repairs       | `/repairs`               |

If an editor moves `Piano lessons` under a different parent, Capell rebuilds the URL and can preserve the old path with a redirect.

## Compose pages with ContentSections

Use ContentSections for pages that need more than a rich text body.

| Page    | Useful widgets                                                      |
| ------- | ------------------------------------------------------------------- |
| Home    | Hero, featured categories, event list, lesson CTA, testimonials     |
| Guitars | Product-category intro, staff picks, buying guide links, repair CTA |
| Lessons | Instructor cards, pricing table, FAQ, enquiry CTA                   |
| Repairs | Process steps, turnaround times, before/after media, booking CTA    |
| Events  | Upcoming events list, newsletter signup, location cards             |

Editors can reuse content blocks across pages. A “Spring guitar setup offer” CTA can appear on Home, Guitars, and Repairs without being retyped three times.

## Add articles

Use Blog for guides and announcements:

| Article                                    | Why it helps                    |
| ------------------------------------------ | ------------------------------- |
| “How to choose your first acoustic guitar” | Search-friendly evergreen guide |
| “Open mic night: May lineup”               | Event announcement and archive  |
| “When does a guitar need a setup?”         | Supports the repairs page       |

Blog pages get archive and tag views, RSS, and sitemap entries.

## Configure addresses and branch details

Use Address for branch data. Attach an address to each site so the frontend can show consistent contact details:

| Branch   | Data to store                                 |
| -------- | --------------------------------------------- |
| Downtown | Street address, country, phone, opening hours |
| North    | Street address, country, phone, opening hours |

The same data can feed contact pages, footer widgets, structured data, and map embeds.

## Set up languages

Add Spanish for the main sales pages:

| Field                       | Translate it |
| --------------------------- | ------------ |
| Page titles                 | Yes          |
| Slugs                       | Yes          |
| Body copy                   | Yes          |
| Media alt text              | Yes          |
| Navigation labels           | Yes          |
| SEO titles and descriptions | Yes          |

Capell generates clean localized URLs and the matching `hreflang` tags, so search engines can connect each language version.

## Use Publishing Studio for campaigns

For a holiday campaign, create a workspace called “Winter Sale”.

Put these changes in the workspace:

- homepage hero swap;
- guitars category CTA;
- repairs discount banner;
- blog announcement;
- navigation link to the campaign page.

Editors preview the full campaign together. An approver reviews it once and publishes the workspace when the campaign is ready.

## Tune SEO and discovery

Use Site Discovery for XML sitemaps. Use SEO Suite for:

- social metadata;
- JSON-LD structured data;
- robots controls;
- AI-assisted title and description suggestions.

For the music store, prioritize structured data for the organization, local branches, articles, breadcrumbs, and events.

## What this saves

Without Capell, a team would build most of this manually: page nesting, redirects, media fields, content blocks, translations, permissions, previews, publishing, sitemaps, structured data, and cache invalidation.

With Capell, the team spends that time on the parts that make the store unique: page types, theme design, ecommerce links, lesson booking, event integrations, and branch-specific content.
