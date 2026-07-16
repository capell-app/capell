# Extension surface vocabulary

Capell packages are installable extension units. A package should declare what it owns in `capell.json`, register runtime behavior through providers, and keep public output safe for anonymous visitors and cached HTML.

## Core terms

| Term                     | Meaning                                                                                                                                                                                                            |
| ------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Package                  | A Composer package that can be installed, enabled, tested, upgraded, disabled, and reported independently.                                                                                                         |
| Extension                | The Capell capability a package contributes through extension points. "Module" is informal product language; prefer package or extension in code, manifests, and docs.                                             |
| Surface                  | The part of Capell a package affects: content, admin, frontend, workflow, delivery, operations, integrations, or marketplace.                                                                                      |
| Contribution             | A declared package-owned capability such as an admin resource, frontend component, render hook, asset, route, migration, scheduled job, permission, setting, or health check.                                      |
| Capability               | A typed behavior flag used by reports and safety checks, for example `public-form`, `render-hook`, `frontend-assets`, or `cache-blocking`.                                                                         |
| Install impact           | The summary of what installing a package adds: dependencies, migrations, settings, permissions, commands, admin surfaces, frontend surfaces, cache impact, public output, health checks, support, and screenshots. |
| Public output safety     | The rule that anonymous and non-admin frontend HTML must not expose editor controls, model IDs, field paths, permissions, package internals, selectors, or signed admin URLs.                                      |
| Editor workspace         | The authenticated admin environment where package authoring, preview, workflow, and management surfaces can appear. It must not leak into public Blade output.                                                     |
| Frontend-owned rendering | Public rendering behavior owned by frontend/theme/runtime code, with hydrated data passed into Blade instead of queries or editor concerns inside public views.                                                    |
| Marketplace proof        | Manifest metadata, screenshots, support policy, compatibility, commercial tier, and tests proving what the extension adds and where it appears.                                                                    |

## Surface taxonomy

| Surface        | Use for                                                                                           |
| -------------- | ------------------------------------------------------------------------------------------------- |
| `content`      | Content models, page types, blueprints, fields, and structured editorial data.                    |
| `admin`        | Filament resources, pages, widgets, settings, permissions, and editor workspace tools.            |
| `frontend`     | Public rendering, widgets, render hooks, themes, routes, and frontend assets.                     |
| `workflow`     | Publishing, approvals, reviews, notifications, and lifecycle automation.                          |
| `delivery`     | Caching, static export, asset optimization, invalidation, and response delivery.                  |
| `operations`   | Diagnostics, health checks, scheduled jobs, upgrade tooling, and maintenance commands.            |
| `integrations` | External services, webhooks, provider adapters, imports, and exports.                             |
| `marketplace`  | Listing metadata, commercial terms, screenshots, support, trust, and install authorization.       |
| `console`      | Console commands and command-line install, setup, diagnostics, or maintenance workflows.          |
| `shared`       | Runtime-neutral package code, metadata, settings, contracts, and services shared across surfaces. |

Use the closest surface that explains the user-visible or operational impact. A package can declare more than one surface, but each declared surface should map to real providers, contributions, commands, settings, or documentation.

## Manifest to runtime flow

1. Composer makes the package classes available.
2. `capell.json` declares identity, surfaces, dependencies, providers, contributions, capabilities, performance, commercial metadata, and marketplace proof.
3. Provider buckets register the actual runtime behavior for install, admin, frontend, or console contexts.
4. Runtime registries and typed contracts expose the behavior to Capell.
5. Capability and install-impact reports summarize the package for CLI, admin, marketplace, docs, and release checks.

The manifest describes intent. Providers and registries execute behavior. Tests prove both agree.

Every deliberate API, DTO, event, tag, config key, render hook, and testing surface is classified in the [extension surface catalogue](extension-surface-catalog.md) as `stable`, `experimental`, or `internal`. Stability is explicit executable metadata; it is never inferred from a namespace.

## Naming guidance

Use **package** for the installable unit, **surface** for the area affected, **contribution** for a concrete registered thing, **capability** for typed behavior, and **install impact** for what changes after install. Avoid mixing these terms with generic plugin or hook language unless you are talking about a specific API such as a render hook.
