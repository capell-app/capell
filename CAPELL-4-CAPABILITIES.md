# Capell 4 capability brief

Last reviewed: 2026-07-08

This is a handoff document for people who need a fast, honest view of what Capell 4 offers, what its optional package ecosystem adds, and what the attached commercial-site plans are asking the public Capell site to communicate.

Status labels:

- **Core**: available in the `capell-4` host monorepo.
- **Package**: available through a first-party optional package, normally from the sibling `capell-packages-4` monorepo.
- **Planned**: proposed by `PLAN2.md` or `PLAN2-ADDITIONS.md` for the commercial/docs site or future product framing.

## The short version

Capell is a package-based CMS foundation for Laravel teams building structured websites, campaign hubs, portals, and content-heavy Laravel products.

It keeps content, routing, roles, publishing, pages, media, workflows, and frontend delivery inside Laravel instead of pushing them into a separate CMS stack. The core product provides the site/page/content model, Filament admin, public rendering, package lifecycle, installer, marketplace integration, cache-aware frontend delivery, and extension points. Larger capabilities ship as optional packages.

The strongest commercial story is:

- **Laravel-owned CMS**: content stays in the app, using Composer, Eloquent, queues, Blade, tests, policies, and deployments the team already owns.
- **Filament-native admin**: Capell gives Filament a CMS product layer: page trees, URLs, publishing state, media, users, settings, dashboards, extensions, and package-owned screens.
- **Structured, package-safe extension model**: packages add fields, resources, widgets, settings, migrations, render hooks, assets, and workflows without patching host classes.
- **Multi-site and multi-language foundations**: sites, domains, languages, translated URLs, translated field values, canonical/alternate URL support, and site-aware settings are first-class.
- **Performance and public-output safety**: frontend rendering is cache-aware, static-HTML compatible, and explicitly designed so public/cached HTML never leaks editor internals.
- **Marketplace-led growth**: optional packages can be browsed, acquired, authorized, and connected through Marketplace surfaces while local package lifecycle remains visible in Admin.

## What Capell 4 includes

| Area                     | Status             | What it gives teams                                                                                                                                                                                          |
| ------------------------ | ------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Core content model       | Core               | Sites, languages, pages, page URLs, layouts, themes, media records, translations, package state, settings, install/upgrade primitives, and registries.                                                       |
| Filament admin           | Core               | Dashboard, pages, sites, languages, layouts, themes, media, users, roles, settings, extensions, site health, recovery shell, lockdown, and package-owned admin surfaces.                                     |
| Public frontend          | Core               | Site/page/language resolution, layout/theme context, Blade/Livewire rendering, render hooks, asset aggregation, cache headers, ETags, minification, and static-cache integration points.                     |
| Installer                | Core               | Browser setup flow, install preflight, package/theme selection, step progress, install guide patches, and setup package removal.                                                                             |
| Marketplace              | Core               | Admin-side catalogue browsing, account connection, domain trust, licence activation, install/upgrade authorization records, heartbeat/update notices, package operations, and deployment handoff.            |
| Package extension system | Core               | Capell manifests, package discovery, install workflows, settings schemas, admin bridges, frontend hooks, Tailwind source registration, maker commands, and extension audits.                                 |
| Performance layer        | Core plus packages | Page cache architecture, model URL cache, ETags, fragment caching, lazy hydration, critical/deferred asset registration, error-page cache regeneration, and optional HTML cache/frontend optimizer packages. |
| Operations               | Core plus packages | Site Health, Lockdown, upgrade ledger, rollback helpers, diagnostics hooks, Marketplace troubleshooting, queue/cache checks, and optional operational packages.                                              |
| Developer tooling        | Core               | Composer scripts, Pest suites, PHPStan/Pint/Rector/ESLint checks, maker commands, extension playground, extension audit, and docs for package boundaries.                                                    |

## Product architecture

Capell is deliberately split between host packages and optional packages.

The normal content path is:

```text
Site -> Page -> Layout and content fields -> Rendered frontend page
```

The normal write path is:

```text
Admin form or HTTP request
    -> Data object
    -> Action::run()
    -> Core model write
    -> Events, subscribers, and cache invalidation
    -> Frontend request resolves the updated page
```

This matters because pages affect URL history, redirects, breadcrumbs, cache keys, navigation, public output, permissions, and package hooks. Capell expects writes to go through Actions and structured state to cross package/HTTP/Livewire/Filament boundaries through Data objects.

## Host packages

| Package     | Composer package         | Status | What it owns                                                                                                                                                                                                      |
| ----------- | ------------------------ | ------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Core        | `capell-app/core`        | Core   | Main schema, models, registries, settings orchestration, package registry, install/upgrade flow, migrations, cache helpers, static-site extension points, lifecycle subscribers, and maker infrastructure.        |
| Admin       | `capell-app/admin`       | Core   | Filament panel, resources, pages, dashboard Filament widgets, settings UI, users, media UI, admin policies, admin bridges, schema hooks, dashboard customisation, user menu tools, and operational admin screens. |
| Frontend    | `capell-app/frontend`    | Core   | Public routes, site/page/language loading, render context, Blade/Livewire frontend surfaces, frontend hooks, Tailwind asset registration, cache-aware middleware, ETags, minification, and public-output safety.  |
| Installer   | `capell-app/installer`   | Core   | Temporary browser installer, preflight checks, install progress/reporting, install guide patches, package/theme selection, reinstall safety, and installer cleanup.                                               |
| Marketplace | `capell-app/marketplace` | Core   | Marketplace catalogue browsing, account connection, domain verification, install authorization, update/advisory heartbeat, theme install intents, and deployment/composer handoff.                                |

## Editorial and admin capabilities

Capell Admin is the working surface for editors, administrators, and developers.

**Core admin screens**

- Dashboard: setup health, package state, content activity, cache state, work queues, and role-aware widgets.
- Pages: page tree, create/edit flow, page URLs, layout assignment, publishing dates, preview, duplicate, bulk move, export, and package-added fields or actions.
- Sites and languages: domains, default site/language state, locale-aware editing, site-aware settings, and language records.
- Layouts and themes: records connecting page content to frontend presentation.
- Media: uploads, metadata, focal points, localised alt text, crop/preview support, and pluggable backend support.
- Settings: Core, Admin, Frontend, package, dashboard, and theme settings.
- Extensions: installed package state, package settings/control pages, lifecycle actions, and Marketplace alerts when Marketplace is installed.
- Site Health: read-only readiness checks for public traffic, cache, safety, static output, optimizer state, and server/runtime state.
- Recovery: import/export shell, with execution owned by an optional recovery/migration package.
- Lockdown: emergency public frontend lockdown and break-glass admin access.
- Users and permissions: users, roles, permissions, approval policies, package resources, and role-scoped admin behaviour.

**Admin extension points**

- `AdminBridge` and `AdminBridgeRegistrar` for package-owned resources, pages, widgets, settings, configurators, and relation managers.
- `CapellAdmin::contributeToAdminSurface(...)` for direct admin surface contributions.
- `SettingsSchemaRegistry::register(...)` and `registerSettingsClass(...)` for package settings screens.
- Tagged schema extenders for pages, sites, layouts, users, page translations, tables, header actions, publish panels, and export modals.
- `AdminToolItem::TAG` for admin header tools.
- `CapellAdmin::registerDashboardFilamentWidget(...)` and dashboard configurators for operational widgets.
- `AdminEventRegistry` and subscriber APIs for lifecycle callbacks.
- Generalised publish status panel extenders for package-owned publishing actions without copying the core panel.

## Content, sites, URLs, and publishing

Capell's core model is built around structured page records, not loose templates.

| Capability                          | Status  | Notes                                                                                                                      |
| ----------------------------------- | ------- | -------------------------------------------------------------------------------------------------------------------------- |
| Page tree                           | Core    | Nested pages with parent/child URL implications, breadcrumbs, navigation impact, and cache invalidation.                   |
| Page URLs                           | Core    | Localised URLs, slug handling, URL history, redirects, and automatic redirect records when published URLs change.          |
| Page types                          | Core    | Package-safe page type registration through `CapellCore::registerPageType(...)`.                                           |
| Layouts and blueprints              | Core    | Layout records connect content to rendering; blueprints shape editing, rendering, reuse, and package-owned content models. |
| Sites                               | Core    | Site records own domains, languages, settings, pages, and theme choices.                                                   |
| Languages                           | Core    | Site language records support localised URLs, labels, field values, and frontend resolution.                               |
| Media                               | Core    | Default Spatie MediaLibrary-backed media contracts, with optional Curator-backed Media Library package.                    |
| Publishing approvals and scheduling | Package | Advanced workflows are owned by `capell-app/publishing-studio`, not by core Admin alone.                                   |
| Draft/private preview snapshots     | Package | `capell-app/filament-peek` adds unsaved private page preview snapshots.                                                    |

## Public frontend delivery

Frontend resolves public traffic and renders published pages. It owns:

- current site resolution from the request host;
- language and page URL resolution;
- page, layout, theme, page type, translations, and render variables loaded before Blade;
- frontend components, render hooks, media helpers, registered assets, cache headers, ETags, static cache integration, and minification.

Public Blade views must receive hydrated render data. They should not run database queries, lazy-load relationships, or discover package/editor state from inside a view.

## Public HTML safety

This is a defining Capell boundary.

Anonymous users, signed-in non-admin users, crawlers, cached HTML, static exports, previews served outside admin, and CDN copies must never receive:

- authoring controls, editor scripts, or admin toolbar markup;
- model IDs, field paths, permissions, package names, or internal selectors;
- signed Filament editor URLs;
- editable markers or hidden admin-only diagnostics;
- public Blade output that lazy-loads database state.

In-page editing is a post-load admin feature. The public page loads as ordinary public HTML. Only an authenticated admin beacon response may add edit controls for that admin session.

This lets the same cached HTML stay safe for anonymous visitors, normal signed-in users, admins, crawlers, static exports, and CDN caches.

## Performance and caching

Capell's performance model is layered.

| Layer                           | Status  | What it does                                                                                                                |
| ------------------------------- | ------- | --------------------------------------------------------------------------------------------------------------------------- |
| Page cache architecture         | Core    | Static HTML/cache-aware page delivery with model and listing hydration layers.                                              |
| Model URL cache                 | Core    | Tracks URL-to-model dependencies so edited records can invalidate every cached URL they affected.                           |
| ETags and conditional responses | Core    | Reduces bandwidth for unchanged frontend pages.                                                                             |
| Fragment caching                | Core    | Caches expensive Blade fragments with surrogate keys.                                                                       |
| Cache invalidation registry     | Core    | Lets packages register model-to-cache dependency patterns; core site logo media changes invalidate public frontend output.  |
| Lazy page hydration             | Core    | Avoids unnecessary eager loads when cold cache hits do not need the full page graph.                                        |
| Critical asset registry         | Core    | Registers critical CSS and deferred scripts, with middleware for browser resource hints.                                    |
| HTML cache                      | Package | Static HTML cache, dependency indexing, and cache administration.                                                           |
| Frontend Optimizer              | Package | Profile-based CSS/JavaScript delivery for public pages; planned direction requires Node/Playwright critical CSS generation. |

The commercial plan adds explicit performance budgets by page archetype: Home under 2.0s LCP, Docs under 1.8s LCP, and other key archetypes generally under 2.2-2.5s LCP with tight CLS/TBT/page-size budgets.

## Marketplace and package trust

Marketplace connects a local Capell installation to Capell App.

It can:

- browse extension listings from Admin;
- connect a Capell account to the local installation;
- verify public production domains when stronger trust is required;
- request install and upgrade authorization;
- represent purchase, activation, authorized install, installed, and incompatible states;
- install theme packages independently through metadata such as `kind`, `themeKey`, and `extends`;
- publish Composer changes through Deployments when available, otherwise show the Composer command;
- send installed package snapshots for heartbeat/advisory checks;
- store update advisories and per-user dismissals.

Local installed extensions remain managed from the installed Extensions table. Marketplace can authorize and guide installation, but local enable/disable/uninstall remains local admin behaviour.

## Installation, upgrades, and operations

Capell supports both fresh Laravel installs and existing Laravel apps.

Common host commands:

- `php artisan capell:install`: main install workflow; can seed demo data, choose package mode, configure a theme, create users, clear caches, generate sitemaps when available, and install developer tooling.
- `php artisan capell:upgrade`: host upgrade flow for migrations, registered upgrade steps, cache cleanup, and upgrade ledger checks.
- `php artisan capell:rollback`: rollback a recorded upgrade step.
- `php artisan capell:extension-install`: run install workflows for Composer-installed packages.
- `php artisan capell:extension-audit`: validate a package directory or manifest against extension contracts.
- `php artisan capell:extension-playground`: inspect an extension package or manifest without installing it.
- `php artisan capell:make` and legacy maker wrappers: scaffold Actions, Data objects, extenders, schemas, types, and package code.

The recommended production upgrade shape is:

```bash
composer update capell-app/capell -W
php artisan capell:upgrade
php artisan optimize:clear
php artisan queue:restart
```

## Developer experience

Capell is built for Laravel teams, not for a separate CMS runtime.

Developer-facing strengths:

- Composer packages and standard Laravel service providers.
- Filament resources, pages, widgets, forms, tables, policies, and macros.
- Actions for domain behaviour.
- Data objects for structured state.
- Eloquent models and relationships.
- Laravel queues, events, middleware, config, cache, and migrations.
- Pest tests, PHPStan, Pint, Rector, ESLint, Prettier, and Testbench package testing.
- Package manifests with install workflows, settings, permissions, assets, providers, migrations, docs, screenshots, and Marketplace metadata.
- Clear host/package/app boundaries so optional features do not leak into core.

## Documentation surface in the repo

The current docs already cover:

- getting started, quickstart, install matrix, install guide, first session, and a music store CMS example;
- why Capell, how Capell works, AI-ready Capell, and Capell Learn;
- Admin interface, setup, admin domains, dashboard Filament widgets, media management, recovery, admin bridges, and generated theme images;
- Frontend request flow, public HTML safety, media rendering, render hooks, widgets, Tailwind assets, Blaze support, and frontend testing;
- package authoring, package anatomy, extension points, service providers, lifecycle, migrations, data/actions/settings, testing, and troubleshooting;
- performance: page cache, model URL cache, ETags, fragment caching, critical assets, invalidation, and lazy hydration;
- operations: Site Health, Lockdown, upgrading, Marketplace debugging, and troubleshooting;
- development: local setup, commands, configuration, seeders, diagnostics, settings migrations, package boundaries, and docs ownership;
- reference docs: ERD, architecture diagrams, glossary, credits, and package catalogue.

The commercial plan asks for a more formal public Docs IA:

```text
Docs
├── Quick start
├── Concepts
│   ├── Pages and URLs
│   ├── LayoutBuilder
│   ├── Packages
│   ├── Publishing workflow
│   └── Tenants and multi-site
├── Guides
├── Reference
├── Recipes
├── Upgrade guides
└── Changelog
```

Planned docs requirements include versioning, search, Edit this page links, per-page feedback, copyable code samples, language toggles, anchors, table of contents, breadcrumbs, and OG cards.

## Commercial-site plan focus

The attached plans are not just asking for a content refresh. They define how Capell should be understood commercially.

### Primary navigation

Planned primary nav:

```text
Why Capell | Solutions | Platform | Marketplace | Pricing | Docs | Talk to founder
```

Key planned route decisions:

- `/why-capell` becomes the core narrative page.
- `/marketplace` becomes the canonical marketplace hub.
- `/marketplace/browse` remains the filtered package browser.
- `/pricing` joins primary navigation.
- `/solutions/content-leads` replaces `/solutions/marketing-teams`.
- Platform splits into Core Features and Advanced Platform.
- Roadmap moves under `/platform/roadmap`.
- Resources keeps Learn, Showcase, Migration, Comparisons, and What's New.

### Planned commercial page set

| Surface              | Planned routes or pages                                                                                                                     | Purpose                                                                                                            |
| -------------------- | ------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------ |
| Why Capell           | `/why-capell`                                                                                                                               | Main narrative: Laravel-owned CMS, package model, Filament admin, public delivery, marketplace, and roadmap truth. |
| Solutions            | Agencies, product teams, content leads, outcome pages                                                                                       | Show audience-specific workflows and value, with capability labels.                                                |
| Platform             | Content management, page building, publishing workflow, packages, developer experience, site operations, APIs/integrations, SEO/performance | Explain the platform in buyer-friendly feature pages.                                                              |
| Marketplace          | `/marketplace`, `/marketplace/browse`, package detail pages                                                                                 | Package discovery, trust, compatibility, install intent, authors, ratings, and package suites.                     |
| Pricing              | `/pricing`                                                                                                                                  | Open-core/free baseline, paid groups, marketplace economics, FAQs, waitlist and founder-call CTAs.                 |
| Docs                 | `/docs` and versioned docs pages                                                                                                            | Credibility surface for Laravel developers evaluating the product.                                                 |
| Migration resources  | WordPress, custom Filament admin, headless CMS                                                                                              | SEO and conversion pages for teams leaving another content setup.                                                  |
| Comparison resources | `/resources/comparisons`                                                                                                                    | Honest fit matrix against custom Filament, Laravel CMS tools, hosted builders, headless CMS, and Capell.           |

### Planned trust, open-source, and community surfaces

The additions plan calls out trust as a missing credibility signal.

Planned pages:

- `/open-source`: open core story, OSS vs commercial split, licence, governance, contribution model.
- `/trust`: security, compliance, uptime, data residency, incident history.
- `/security`: disclosure policy, security.txt, supported versions, CVE history.
- `/build-in-public`: founder updates, monthly notes, public metrics.
- `/community`: Discord/forum/GitHub Discussions, code of conduct, recognition.
- `/community/contribute`: issues, RFCs, PRs, package authoring.
- `/community/showcase`: community-built sites.
- `/community/events`: office hours, webinars, Laracon presence, monthly calls.
- `/experts`: agencies and freelancers who build with Capell.

Planned reusable trust strip:

```text
[ GitHub stars ] [ Contributors ] [ Packages ] [ Sites in production ] [ Latest release ]
```

The home page should also surface GitHub stars, contributor count, current release, last release date, Laravel ecosystem proof, and founder identity.

### Planned pricing framing

The plan proposes pricing even if early access stays contact-led.

Pricing should cover:

- tier framing such as Solo / Team / Studio / Enterprise;
- capability matrix using Core / Package / Roadmap labels;
- what stays free forever;
- what is paid: hosted, Marketplace access, premium packages, SLA support;
- marketplace economics: author revenue share, payout model, author tier;
- open source vs paid, self-host vs cloud, package licensing, refund policy, education/OSS pricing;
- waitlist CTA with intended tier, founder-call CTA, and pricing FAQ.

### Planned account portal framing

The additions plan recommends keeping customer-account capability in the story, but as a buyer-facing platform feature.

Planned portal areas:

- `/account`: sign in;
- `/account/dashboard`: domains, packages, licences, instances, billing;
- `/account/domains`: verify and manage domains;
- `/account/packages`: installed packages, updates, entitlements;
- `/account/billing`: invoices, payment method, plan changes;
- `/account/support`: tickets and founder-call booking;
- `/account/team`: collaborators.

### Planned content surfaces

Missing or expanded commercial surfaces:

- Blog for long-form articles, opinion, technical deep dives, and SEO.
- Changelog as terse versioned release notes.
- What's New as editorial product updates.
- Newsletter page with RSS/email/social subscription.
- Brand/press kit.
- Partners and experts.
- Customers, logos, case studies, and quotes.
- Branded 404 with search, top links, and CTA.
- Public status page.
- Legal pages: privacy, terms, cookies, DPA, acceptable use, subprocessors, security, and `/.well-known/security.txt`.

### Planned site search, demo, analytics, and governance

The commercial site plan adds:

- PostHog for analytics, funnels, scroll depth, session replay, A/B tests, and conversion events.
- Waitlist/demo/founder-call/docs/GitHub/download/newsletter/marketplace/pricing/migration engagement tracking.
- Scroll-depth CTA at 50-60 percent.
- Exit-intent or idle newsletter/save-for-later prompt.
- Sticky secondary CTA on long migration/comparison pages.
- Whole-site search across docs, migration guides, comparisons, blog, changelog, and Marketplace metadata.
- Cmd+K palette across the public site.
- Read-only public demo at `demo.capell.dev`.
- Later per-visitor sandboxes and embedded LayoutBuilder iframe demo.
- Waitlist onboarding sequence, founder-call route, pre-call briefing, and office-hours/community handoff.
- Content ownership and review cadence per area, with stale-review warnings.
- Roadmap board with Now / Next / Later / Shipped lanes, upvotes, issue/PR links, and shipped links to changelog/blog.

### Planned artwork system

Every commercial page should have Capell-specific product artwork, not generic SaaS illustration.

Artwork should show realistic Capell concepts: Laravel/Filament admin surfaces, page trees, LayoutBuilder blocks, package cards, workflow lanes, preview panes, public site output, marketplace trust, migration maps, and operational dashboards.

Style direction:

- premium British SaaS product illustration;
- warm neutral background;
- dark green, gold, and ink accents;
- realistic CMS panels and status chips;
- no abstract blobs, cartoon characters, neon gradients, fake URLs, or readable fake body copy;
- hero 16:9, card 4:3, thumb 1:1;
- WebP at 2x, dark-mode-safe contrast, descriptive alt text, fixed aspect ratio, and tested responsive output.

## Planned page archetypes

The plan defines repeatable templates for consistent implementation.

**Solution page**

1. Hero with H1, intro, CTA pair, and artwork.
2. Trust strip.
3. Three benefit cards.
4. Three-step "how it works" flow.
5. Capability table with Core / Package / Roadmap labels.
6. Proof block.
7. Related solutions.
8. FAQ.
9. Final CTA.

**Platform feature page**

1. Hero.
2. Demo or screenshot block.
3. "Built for" chips.
4. Feature deep dive with artwork.
5. Developer hook with code/admin screenshot and docs CTA.
6. Compatibility/requirements box.
7. Related platform pages.
8. FAQ.
9. Final CTA.

**Migration page**

1. Hero with from/to visual.
2. What transfers cleanly / needs mapping / changes on purpose.
3. Migration outline.
4. Tooling and packages.
5. Time estimate ranges.
6. Risk and rollback notes.
7. Partner CTA.
8. FAQ.
9. Final CTA.

**Comparison page**

1. Hero by project shape.
2. At-a-glance table.
3. When Capell fits / when it is wrong.
4. Capability matrix.
5. Migration link.
6. FAQ.
7. Final CTA.

## Planned SEO focus

Priority planned SEO pages:

- `/resources/migration/from-custom-filament-admin`
- `/resources/migration/from-wordpress`
- `/resources/migration/from-headless-cms`
- `/resources/comparisons`
- `/platform/apis-integrations`
- `/platform/seo-performance`

Every commercial page should define:

- primary keyword plus 2-4 secondary keywords;
- meta title and meta description;
- canonical URL;
- OG image and Twitter card using page artwork;
- JSON-LD where appropriate;
- internal links to parent, siblings, children, and one conversion page.

Important keyword positions from the plan include Laravel CMS, Laravel-owned CMS, Laravel CMS for agencies, CMS inside Laravel app, Laravel page builder, Laravel headless CMS, SEO Laravel CMS, Laravel CMS extensions, and WordPress to Laravel CMS.

## Planned voice and CTA rules

Commercial copy guardrails:

- British English.
- Sentence case headings.
- No exclamation marks.
- Avoid "supercharge", "unlock", "elevate", "robust", "seamless", "powerful", and "blazing fast".
- Use "proper", "properly", "tidy", and "fit for purpose" at most once per page.
- Do not mention the referenced competitor in public copy.
- Mark mixed maturity claims as Core, Package, or Roadmap.

CTA bank:

- Primary: `Join the waitlist`, `Talk to the founder`, `Get early access`.
- Secondary: `Try the demo`, `Read the docs`, `Browse the marketplace`, `Read the migration guide`, `Book office hours`, `See pricing`.
- Tertiary: `Star on GitHub`, `Subscribe for monthly updates`, `Download the brand kit`, `Compare Capell with X`.
- Forbidden: `Learn more`, `Click here`, and vague `Get started`.

## Optional package ecosystem

First-party add-ons live in `capell-packages-4`. They should be presented as Capell capabilities, but not as built-in core behaviour.

### Foundation and content

| Package          | Composer package              | What it provides                                                                                                            |
| ---------------- | ----------------------------- | --------------------------------------------------------------------------------------------------------------------------- |
| Address          | `capell-app/address`          | Country, region, and address data, selectors, admin records, and flag rendering.                                            |
| Block Library    | `capell-app/block-library`    | Shared typed block definitions and registration contracts used by layout/content packages.                                  |
| Blog             | `capell-app/blog`             | Article publishing, archive pages, tag pages, article elements/blocks, frontend page components, and sitemap contributions. |
| Content Sections | `capell-app/content-sections` | Reusable content section records, content-first editing, element assets, theme layout areas, and Livewire/public rendering. |
| Events           | `capell-app/events`           | Event records, venues, occurrences, RSVPs/registrations, calendar pages, iCalendar feeds, and Event schema.                 |
| Hero             | `capell-app/hero`             | Default home-page hero block, rendering, and layout setup.                                                                  |
| Layout Builder   | `capell-app/layout-builder`   | Visual layout composition, content-first editing, layout containers, editor modes, and public layout rendering.             |
| Media Library    | `capell-app/media-library`    | Awcodes Curator backend integration, Curator picker support, media health, and Spatie media migration support.              |
| Navigation       | `capell-app/navigation`       | Site/language-scoped navigation trees, page navigation fields, sync actions, and frontend loading support.                  |
| Notes            | `capell-app/notes`            | Contextual notes, assignments, mentions, reminders, and admin record collaboration.                                         |
| Tags             | `capell-app/tags`             | Shared taxonomies, tag management, taggable relationships, reusable tag input, and model traits.                            |
| Foundation Theme | `capell-app/foundation-theme` | Default frontend theme assets, Tailwind pipeline, Blade directives, URL generation, and SVG/media rendering.                |

### Authoring and publishing

| Package             | Composer package                 | What it provides                                                                                                                           |
| ------------------- | -------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------ |
| Frontend Authoring  | `capell-app/frontend-authoring`  | Authenticated admin beacon, in-page edit manifest, signed edit routes, and cache-aware field saves from public pages.                      |
| Frontend Optimizer  | `capell-app/frontend-optimizer`  | Profile-based CSS and JavaScript delivery for public pages.                                                                                |
| HTML Cache          | `capell-app/html-cache`          | Static HTML cache, dependency indexing, cache administration, and static-site warming.                                                     |
| Publishing Studio   | `capell-app/publishing-studio`   | Revisions, approvals, previews, compare, scheduling, controlled publishing, restore, rollback, release workspaces, and editorial activity. |
| Translation Manager | `capell-app/translation-manager` | File-based Filament editor for Laravel language files and safe package override writes.                                                    |
| Welcome Tour        | `capell-app/welcome-tour`        | Optional guided onboarding for the Capell admin panel.                                                                                     |
| Filament Peek       | `capell-app/filament-peek`       | Private, unsaved page preview snapshots in Capell Admin.                                                                                   |

### Forms, access, and public workflows

| Package            | Composer package                | What it provides                                                                                                                                  |
| ------------------ | ------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------- |
| Access Gate        | `capell-app/access-gate`        | Public access gates, entitlement checks, gated delivery foundations, and access request flows.                                                    |
| API                | `capell-app/api`                | Public JSON delivery of published Capell page data for integrations and decoupled consumers.                                                      |
| Form Builder       | `capell-app/form-builder`       | Form definitions, fields, encrypted submissions, frontend Livewire rendering, validation, notifications, exports, and submission status handling. |
| Newsletter         | `capell-app/newsletter`         | Subscriber capture, audience workflows, consent state, imports, notifications, and public subscription routes.                                    |
| Password Policy    | `capell-app/password-policy`    | Opt-in password rules, expiry, forced password changes, and password safety enforcement.                                                          |
| Public Actions     | `capell-app/public-actions`     | Safe public submissions that run configured server-side actions, outbound automation dispatch, and integration endpoints.                         |
| Document Lifecycle | `capell-app/document-lifecycle` | Controlled document registry, publication metadata, hashes, acceptance evidence, and document lifecycle governance.                               |

### Growth, search, commerce, and reporting

| Package           | Composer package               | What it provides                                                                                                                                                                             |
| ----------------- | ------------------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Campaign Studio   | `capell-app/campaign-studio`   | Campaign groups, landing pages, CTA blocks, UTM attribution, conversion goals, and campaign insights.                                                                                        |
| Dashboard Reports | `capell-app/dashboard-reports` | Generic dashboard reporting widgets for Capell dashboards.                                                                                                                                   |
| Email Studio      | `capell-app/email-studio`      | Transactional templates, delivery profiles, send audit trails, suppressions, replies, provider events, and tracking diagnostics.                                                             |
| GA4 Reports       | `capell-app/ga4-reports`       | Google Analytics 4 dashboard reporting inside Capell.                                                                                                                                        |
| Insights          | `capell-app/insights`          | First-party visits, events, consent decisions, page views, clicks, journey data, and conversion visibility.                                                                                  |
| Search            | `capell-app/search`            | Frontend search route, configurable drivers, result click tracking, query logging, zero-result reporting, and admin search insights.                                                         |
| SEO Suite         | `capell-app/seo-suite`         | Metadata panels, structured data, social meta, robots controls, audits, broken-link tracking, Search Console insights, AI-assisted content briefs, publish checks, and AI discovery support. |
| Shopify Commerce  | `capell-app/shopify-commerce`  | Site-scoped Shopify Admin API OAuth and catalogue sync for Capell.                                                                                                                           |
| Site Discovery    | `capell-app/site-discovery`    | Public discoverable URL resolution, XML/HTML sitemaps, and discovery outputs.                                                                                                                |

### Operations, agents, and migration

| Package             | Composer package                 | What it provides                                                                                                                                        |
| ------------------- | -------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Agent Bridge        | `capell-app/agent-bridge`        | Agent Bridge servers and capability adapters that expose Capell knowledge and site capabilities.                                                        |
| Agent Delivery      | `capell-app/agent-delivery`      | Public-safe structured page manifests and semantic chunks for agents, search assistants, and machine readers.                                           |
| AI Creator          | `capell-app/ai-creator`          | Reviewed AI-assisted creation sessions for existing Capell sites, including page, content, layout, record, and package recommendation workflows.        |
| AI Orchestrator     | `capell-app/ai-orchestrator`     | AI providers, prompts, structured requests, capability execution, approval levels, and integrations with packages such as Content Sections.             |
| Demo Kit            | `capell-app/demo-kit`            | Repeatable demo content and media for local Capell demos and package screenshots.                                                                       |
| Deployments         | `capell-app/deployments`         | Repository deployment connections and Composer requirement publishing, useful for Marketplace-driven installs.                                          |
| Diagnostics         | `capell-app/diagnostics`         | Operational diagnostics for cache, configuration drift, migrations, packages, registries, queues, permissions, setup health, and Tailwind build status. |
| Site Monitor        | `capell-app/site-monitor`        | External uptime, SSL certificate, domain expiry, and incident monitoring for Capell sites.                                                              |
| Login Audit         | `capell-app/login-audit`         | Login, failed login, logout, admin/user activity metadata, and authentication visibility.                                                               |
| Media AI            | `capell-app/media-ai`            | Optional AI-assisted media actions.                                                                                                                     |
| Migration Assistant | `capell-app/migration-assistant` | Package import workflows, source reads, mapping, preview, validation, execution state, export/import support, and rollback reports.                     |
| WordPress Importer  | `capell-app/wordpress-importer`  | WordPress WXR XML parsing as a source for Migration Assistant.                                                                                          |

### Themes

| Package                  | Composer package                      | What it provides                                                                                         |
| ------------------------ | ------------------------------------- | -------------------------------------------------------------------------------------------------------- |
| Theme Agency             | `capell-app/theme-agency`             | Expressive agency theme renderer and presets for studio, portfolio, and brand-led sites.                 |
| Theme Business Solutions | `capell-app/theme-business-solutions` | Vertical business theme keys and renderer profiles for professional, civic, commerce, and service sites. |
| Theme Corporate          | `capell-app/theme-corporate`          | Restrained corporate theme renderer for B2B, public sector, and professional-service sites.              |
| Theme Estate Agents      | `capell-app/theme-estate-agents`      | Premium property theme renderer for search, valuations, local guides, agent proof, and viewing requests. |
| Theme Restaurant         | `capell-app/theme-restaurant`         | Premium hospitality theme renderer for menu-led restaurants, reservations, private dining, and events.   |
| Theme SaaS               | `capell-app/theme-saas`               | Product-focused SaaS theme renderer for software and subscription sites.                                 |

## Package product groups for pricing and marketplace

The docs group packages by customer-facing value rather than implementation detail.

| Product group         | Tier    | Packages                                                                                                                           | Buying reason                                                                                                                    |
| --------------------- | ------- | ---------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------- |
| Capell Foundation     | Free    | Content Sections, Blog, Navigation, Tags, Address, Media Library, Frontend Authoring, Foundation Theme, HTML Cache, Site Discovery | The baseline a serious CMS site expects: building, content, menus, media, authoring, theme foundation, caching, and discovery.   |
| Capell Commercial     | Premium | AI Orchestrator, AI Creator                                                                                                        | Centralised AI prompts, provider connectors, approval-aware runs, reviewed creation sessions, and optional package integrations. |
| Capell FormBuilder    | Premium | Form Builder                                                                                                                       | Lead capture, submissions, validation, notifications, exports, and form workflow inside Laravel.                                 |
| Capell Publishing Pro | Premium | Publishing Studio                                                                                                                  | Team editorial workflows with previews, approvals, schedules, revisions, and rollback.                                           |
| Capell Operations     | Premium | Diagnostics, Site Monitor, Login Audit, and related recovery/migration tooling                                                     | Health, uptime visibility, auditability, recovery, package readiness, queues, permissions, config drift, and safer operations.   |
| Capell Growth         | Premium | Insights, Campaign Studio, GA4 Reports                                                                                             | Traffic measurement, campaigns, goals, attribution, and conversion reporting.                                                    |
| Capell Communications | Premium | Email Studio                                                                                                                       | Transactional email templates, profiles, audit trails, suppressions, provider events, replies, and tracking diagnostics.         |
| Capell Search & SEO   | Premium | SEO Suite, Search, Site Discovery                                                                                                  | Discoverability, structured data, audits, AI SEO assistance, site search, sitemaps, and search insights.                         |
| Capell Themes         | Premium | Agency, Corporate, Estate Agents, Restaurant, SaaS, Business Solutions themes                                                      | Faster polished frontend delivery for common commercial site shapes.                                                             |

## What to be careful not to overclaim

- Core does not own the full publishing workflow; approvals, scheduling, revisions, previews, compare, release workspaces, restore, and rollback belong to Publishing Studio.
- Admin owns the Recovery Center shell; real export/import/restore execution belongs to optional migration/recovery packages.
- Frontend owns rendering and hooks; default theme output belongs to Foundation Theme and premium theme packages.
- SEO metadata, audits, sitemaps, and AI discovery are package-backed, mainly SEO Suite and Site Discovery.
- In-page authoring is not public HTML and must never be baked into cached output.
- Marketplace authorizes/guides installs; Composer/deployment still applies the package change.
- Commercial-site plans include roadmap pages, account portal, community, pricing calculator, demo sandboxes, legal/status pages, and analytics flows that are not automatically host-core features.

## Suggested one-page buyer narrative

Capell is a Laravel-owned CMS for teams that have outgrown bespoke Filament admin screens but do not want their content model, routing, roles, publishing, or frontend delivery living in a separate CMS.

Core gives the platform: sites, pages, URLs, languages, layouts, media, settings, Filament admin, frontend rendering, caching, installer, Marketplace, and extension contracts.

Packages add the product depth: visual page building, blog, navigation, media backend swaps, frontend authoring, static HTML cache, publishing workflows, forms, search, SEO, campaigns, analytics, AI, migrations, diagnostics, deployments, and themes.

The commercial site should sell that as an owned Laravel platform with clear maturity labels: **Core** for what ships in the host product, **Package** for optional first-party capabilities, and **Roadmap** for planned commercial/cloud/community features.

## Source docs checked

This brief was compiled from:

- `/Users/ben/Downloads/PLAN2.md`
- `/Users/ben/Downloads/PLAN2-ADDITIONS.md`
- `README.md`
- `docs/README.md`
- `docs/getting-started/*`
- `docs/admin/*`
- `docs/frontend/*`
- `docs/packages/*`
- `docs/performance/*`
- `docs/operations/*`
- `docs/development/*`
- `docs/reference/*`
- `packages/*/README.md`
- `packages/*/docs/*.md`
- `/Users/ben/Sites/packages/capell/capell-packages-4/README.md`
- `/Users/ben/Sites/packages/capell/capell-packages-4/docs/*.md`
- `/Users/ben/Sites/packages/capell/capell-packages-4/packages/*/README.md`
- `/Users/ben/Sites/packages/capell/capell-packages-4/packages/*/docs/*.md`
- host and optional package `capell.json` manifests.
