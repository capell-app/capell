# Package authoring jobs

![Capell Package authoring jobs screenshot](../images/generated/admin/theme-library-admin-flow.png)

Start with the job the package performs, then choose the smallest extension points that make that job installable and testable.

## Add a page type

Use this when the package owns a new kind of content, not just extra fields on an existing type.

1. Declare `content` and usually `admin` in `capell.json` `surfaces`.
2. Add a `page-type` contribution with the class that registers the type.
3. Register the page type from a runtime provider with `CapellCore::registerPageType(...)`.
4. Add admin configurators or schema extenders only for fields the package owns.
5. Add tests that assert the page type appears in the registry and unsupported contexts fall back to the base type list.

Keep rendering data outside public Blade. Load page data in Actions, controllers, payload builders, or view components before rendering.

## Add a frontend widget

Use this when the package renders public HTML through Layout Builder, a frontend component alias, or a render hook.

1. Declare `frontend` in `surfaces`.
2. Declare the closest contribution type: `frontend-component`, `render-hook`, `asset`, `route`, or `section`.
3. Register frontend behavior from the frontend/runtime provider, not an admin-only provider.
4. Pass hydrated data into Blade. Public package views must not query models, lazy-load relations, or inspect the current admin user.
5. Add anonymous public-output safety tests proving no authoring selectors, model IDs, field paths, permissions, package internals, or signed admin URLs leak.

Use `RenderHookRegistry::registerView()` for view-name hooks, `registerInlineBlade()` for inline Blade snippets, `registerCallable()` for closures, and `registerExtension()` for class-based render hooks. Package-owned keyed hooks should use `RenderHookContributionData::view()`, `inlineBlade()`, or `extension()` so diagnostics, stable dedupe keys, and cache-safety metadata stay attached.

## Add publishing workflow

Use this when the package changes editorial state, review, approval, reminders, or release windows.

1. Declare `workflow` and `admin` surfaces.
2. Put state changes in Actions. UI, commands, jobs, and subscribers should delegate to those Actions.
3. Declare permissions and settings in `capell.json`.
4. Register admin pages, widgets, table columns, notifications, or validation subscribers through Admin bridges or typed extenders.
5. Test the Action directly, then test the UI/command only for delegation, permissions, prompts, and visible state.

Packages that affect publishing should also document whether they block publish, warn editors, or provide advisory information only.

## Add cache invalidation

Use this when package data can change public output.

1. Declare `delivery` and any public surface the package affects.
2. Declare cache-related capabilities such as `cache-invalidation`, `cache-blocking`, `public-static`, or `frontend-assets` where accurate.
3. Register model dependencies with `CacheInvalidationRegistry::registerDependency(...)`.
4. Set `performance.cacheSafety` accurately in `capell.json`.
5. Test both the invalidation path and the safe fallback when the package is missing or disabled.

If public output varies by user, role, preview token, workspace, or other private state, mark it clearly. Cached HTML must be safe for anonymous visitors, normal signed-in users, admins, crawlers, and static exports.

## Add marketplace proof

Use this when the package will be listed, sold, bundled, or installed through [Marketplace](../../packages/marketplace/docs/overview.md).

1. Fill `product`, `commercial`, and `marketplace` metadata in `capell.json`.
2. Declare every shipped contribution; do not leave `contributes` empty for a package with runtime surfaces.
3. Add screenshots with useful alt and caption text, and commit referenced assets.
4. Document setup, support, compatibility, safety caveats, and screenshot capture instructions.
5. Add tests that validate manifest metadata, contribution contracts, screenshot paths, docs links, commercial tier, support policy, and compatibility.

Marketplace copy should explain the outcome the package improves before naming implementation details.

## Build one package

The normal path is:

1. Scaffold with `php artisan capell:make-extension vendor/example --profile=minimal --path=packages`.
2. Fill `capell.json` with identity, surfaces, provider buckets, dependencies, contributions, capabilities, performance, commercial, and marketplace metadata.
3. Put domain behavior in `src/Actions` and structured state in `src/Data`.
4. Register runtime behavior from the provider bucket that owns it.
5. Add the admin/editor surface through an Admin bridge when there is more than one admin concern.
6. Add public output through frontend registries with safe hydrated render data.
7. Add migrations and settings migrations with existence guards.
8. Add tests for Actions, manifest/contribution contracts, install/setup behavior, public-output safety, and safe fallbacks.
9. Add screenshots and docs when the package is Marketplace-ready.
10. Run the package tests, audit the manifest, and verify docs links before release.

Use [extension point chooser](extension-point-chooser.md) when deciding which registry or contract fits the job. Use [extension point API reference](extension-point-api-reference.md) only after the job and surface are clear.
