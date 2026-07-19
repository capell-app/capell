# AI-Ready Capell

![Capell AI-Ready Capell screenshot](../images/capell-readme-banner.jpg)

Capell is AI-ready because its CMS foundation is already structured, permissioned, extensible, and safe to render.

AI features work best when they have trustworthy context: page records, URLs, language state, media metadata, package boundaries, workflow state, permissions, queues, cache dependencies, and a clear public output contract. Capell gives Laravel teams that foundation before an AI provider is added.

## What Core Provides

Core does not need to become an AI package to be useful for AI. It gives AI-capable packages the stable CMS context they need:

- structured sites, pages, URLs, translations, layouts, settings, and media relationships;
- multi-site and multi-language primitives;
- queue-ready publishing and cache invalidation;
- package discovery, manifests, install state, and extension contribution types;
- Filament admin surfaces, permissions, and settings boundaries;
- public frontend safety rules that keep admin and authoring state out of anonymous HTML.

That means an AI feature can ask better questions. It can understand which page is being edited, which language is active, which packages are installed, which public URLs may be affected, and where an editor review should happen.

## Deterministic SiteSpec Handoffs

Core provides one canonical SiteSpec contract for local imports and hosted provisioning. A spec declares the site, theme, language, pages, navigation references, remote media, required extensions, and initial visibility. It does not ask core to infer missing content or call an AI provider.

Import a generated contract locally with:

```bash
php artisan capell:site-spec-import storage/app/site-spec.json
```

The importer validates the complete contract before writing, sanitises section HTML, requires requested extensions to be installed, and treats the normalized spec as idempotent. Remote media is accepted only from the declared public HTTPS origin, with redirect, size, aggregate-budget, and image-type controls.

Optional packages own their records. For example, a navigation package registers a typed SiteSpec applier and receives the persisted site plus a page map inside the import transaction. Core therefore exposes a stable seam without importing package-specific models. AI generators remain commercial; deterministic import stays free.

## What AIOrchestrator Adds

`capell-app/ai-orchestrator` is the commercial AI layer. It should own AI-specific dependencies and workflows instead of pushing provider clients into every free package.

AIOrchestrator is the right home for:

- provider connectors;
- prompt templates and run orchestration;
- approval-aware execution;
- AI run history and review state;
- optional integrations with packages such as Content Sections, SEO Suite, media, search, campaigns, and translation workflows.

Core stays lean. Packages opt into AI where the feature needs it.

## AI-Ready Feature Areas

Capell is shaped for practical AI assistance across the CMS:

| Area                  | How Capell helps                                                      |
| --------------------- | --------------------------------------------------------------------- |
| Content planning      | Page trees and package state give prompts real context.               |
| Draft page generation | Draft structures and section suggestions, still subject to review.    |
| SEO and discovery     | Package-aware metadata, internal links, sitemaps, and `llms.txt`.     |
| Translation           | Language and translation state anchor locale-specific drafts.         |
| Editorial review      | Workflow packages turn AI checks into review notes before publish.    |
| Campaigns             | Generate or review campaign pages, CTAs, UTM context, and goals.      |
| Diagnostics           | Summarise site health, broken links, package impact, and cache state. |

## Safety And Governance

AI assistance belongs inside admin and package workflows. Public visitors should not be able to tell that an editor, authoring package, AI run, signed admin URL, model identifier, field path, permission name, or package selector exists.

Keep these rules:

- AI-generated changes should become drafts, suggestions, review notes, or explicitly approved writes.
- Public Blade views should receive hydrated render data and must not query for AI context.
- Cached HTML must stay safe for anonymous users, signed-in non-admin users, admins, crawlers, and static exports.
- Packages should expose AI-capable Actions or extension points without forcing AI dependencies into unrelated packages.
- Provider configuration and prompt execution should be centralised in AIOrchestrator or a purpose-built integration package.

## Developer Path

Start with the CMS foundation:

1. Read [How Capell works](how-capell-works.md) for the page, URL, translation, and layout model.
2. Read [Blueprints](types.md) for how page, widget, layout, and package behaviour is shaped.
3. Read [Package product groups](../packages/product-groups.md) to understand where AIOrchestrator belongs commercially.
4. Read [Extension point API reference](../packages/extension-point-api-reference.md) before exposing package capabilities.
5. Use the core Boost skill's SiteSpec reference when generating or applying deterministic site contracts.
6. Keep the [public HTML safety contract](../frontend/public-html-safety.md) close when touching public rendering, cache, themes, authoring, or AI-assisted output.

The practical rule is simple: let Capell core describe the site, let AIOrchestrator coordinate AI work, and keep public output boring.
