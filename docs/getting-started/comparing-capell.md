# Compare Capell with WordPress and Craft CMS

Capell is the strongest fit when a website is part of a Laravel product and content, application data, queues, tests, deployment, and public rendering should remain in one codebase. WordPress and Craft CMS are mature choices with different centres of gravity; this is a fit check, not a claim that one tool wins every project.

## Quick fit check

| Choose | When this is the deciding constraint |
| --- | --- |
| Capell | The product is already Laravel/Filament, content participates in application workflows, or the team wants Composer packages and normal Laravel extension points. |
| WordPress | Editors need the broadest off-the-shelf theme/plugin ecosystem and the site can live comfortably inside WordPress's runtime and data model. |
| Craft CMS | The project is a content-led bespoke site and the team wants a mature commercial CMS with a purpose-built control panel and content-modelling workflow. |

## Architecture and ownership

| Question | Capell | WordPress | Craft CMS |
| --- | --- | --- | --- |
| Where does it run? | Inside the Laravel application. | In a WordPress application, commonly extended with plugins and themes. | In a Craft application built on Yii, extended with modules and plugins. |
| How is domain behaviour added? | Laravel Actions, services, Eloquent models, queues, events, and Composer packages. | Plugins, hooks, WordPress APIs, and theme code. | Modules/plugins, services, elements, fields, and Craft/Yii APIs. |
| Who owns public HTML? | The Laravel app: Blade, Livewire, Inertia, or a package-owned frontend. | The active theme, block templates, and plugins. | Twig templates or a headless frontend. |
| How does content meet application data? | Through normal application models and explicit package boundaries. | Through WordPress tables/APIs or integration code. | Through Craft elements, custom fields, and integration code. |
| What is the deployment unit? | The Laravel app plus locked Composer/npm dependencies and migrations. | WordPress core, plugins, themes, uploads, database, and environment configuration. | Craft project config, Composer dependencies, templates, database, files, and environment configuration. |

## Editorial and delivery trade-offs

Capell favours developer-owned composition and predictable package contracts. It provides page trees, sites, languages, URLs, media boundaries, permissions, settings, publishing hooks, themes, widgets, and Layout Builder composition without moving the site out of Laravel.

WordPress is usually the fastest choice when a suitable plugin/theme combination already solves the brief. Application-specific workflows can, however, become a negotiation between plugin conventions, WordPress data, and the surrounding product.

Craft CMS is a strong choice for bespoke editorial sites where structured content modelling and a dedicated authoring experience matter more than sharing a Laravel application's runtime. A Laravel team should price the cost of operating a second framework and integration boundary.

## Migration questions to answer

1. Does content need transactions, permissions, or workflows shared with the Laravel product?
2. Must the frontend reuse the application's authentication, queues, cache, or domain services?
3. Is the required feature already proven in the competing CMS ecosystem?
4. Who will maintain bespoke plugins or packages after launch?
5. Can the team export content, media, URLs, and relationships without relying on a hosted service?

If the first two answers are yes, Capell's in-application model is usually the simpler long-term boundary. If the third answer dominates and integration is light, WordPress or Craft may be the more economical choice.

Continue with [Why Capell](why-capell.md), the [install matrix](install-matrix.md), or the [export and exit plan](../operations/export-and-exit.md).
