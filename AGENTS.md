# Agent Guidelines for Capell CMS

## Working Profile

Ben Johnson is a senior Laravel developer based in Birmingham, UK, specializing in scalable CMS platform-builder like Capell, built with Laravel and Filament. He champions clean, maintainable code guided by SOLID principles--Single Responsibility, Open/Closed, Liskov Substitution, Interface Segregation, and Dependency Inversion--ensuring modular, testable architectures that scale without fragility.

## Core Standards

Adheres to PSR-12 coding style, Laravel Naming Conventions (e.g., descriptive method names, thin controllers), and strict typing throughout. Emphasizes DRY (Don't Repeat Yourself) via Traits, Service Classes, and Data Transfer Objects, while applying KISS (Keep It Simple, Stupid) to avoid over-engineering.

## Development Philosophy

Prioritizes one clear solution over multiple options, prototyping with Livewire/Filament first, then integrating via signed routes and Redis caching. Values test-driven development (PHPUnit/Pest with 90%+ coverage), PHPStan level 10, and pre-commit linting. Documents only public APIs in `docs/`, seeks clarification on unclear requirements, and maintains lean dependency trees--audit before adding Composer packages.

This pragmatic approach delivers robust, performant applications efficiently, without unnecessary complexity or overwork.

I've always liked to deliver the best so if you think of a better feature or a better way of doing something than what I've asked, always suggest it.

Capell is a Laravel-based CMS. Admin panel via Filament.

## Documentation and Test Coverage

- Most product or architecture changes require documentation updates alongside the code, not as a later cleanup task.
- Meaningful behaviour must have full focused test coverage before completion. Start narrow, then broaden verification when the change touches shared contracts, frontend rendering, queues, packages, or admin workflows.
- When changing extension points, package APIs, asset pipelines, or rendering behaviour, update the relevant package docs and include tests proving both the expected behaviour and the safe fallback path.

## Non-negotiables

- `declare(strict_types=1);` in every PHP file.
- PHP 8.4 only — typed class constants are allowed; avoid PHP 8.5+ syntax.
- User-facing strings via `__('capell-admin::...')`. Filament labels via method overrides (`getNavigationLabel()`, `getModelLabel()`), never static string properties.
- No single-letter or cryptic variable names — closures, migrations, example prose included.

## Frontend Authoring Safety

- Non-admin frontend users must never be able to tell an in-page editor exists. Public Blade, cached HTML, theme output, and frontend assets must not contain authoring HTML, authoring JavaScript, editable markers, model IDs, field paths, labels, permissions, package names, selectors, or signed editor URLs.
- Frontend authoring is a post-load admin feature. The page loads as ordinary public HTML, the browser calls the beacon, and only an authenticated admin beacon response may add edit controls or signed Filament editor URLs.
- Unique/static HTML caching depends on this rule. Cached HTML must stay safe to serve to anonymous visitors, normal signed-in users, admins, crawlers, and static exports.
- When touching frontend rendering, page cache, themes, or beacon code, add or preserve tests proving anonymous and non-admin responses expose no authoring surface.
- Public Blade views must not execute database queries or lazy-load relationships. Load public render data in controllers, Actions, Livewire components, view composers, Capell payload builders, or explicit view component classes, then pass hydrated data into views. Treat `::query()`, `DB::`, `loadMissing()`, relationship fallback access like `$model->media->first()`, and direct model lookups in public package views as performance bugs.

## Approved Frontend Optimizer Direction

- Create a new optional `capell-app/frontend-optimizer` package for optimized public frontend delivery.
- PHP/Laravel owns Capell concepts: asset graph, layout scopes, render profiles, invalidation, queue jobs, database state, manifests, diagnostics, and public rendering.
- Node/Playwright is required for critical CSS generation. Do not add a Beasties/Critters fallback. Generation failures should fail jobs loudly while public pages continue loading normal CSS/JS safely.
- Default optimization scope is layout. Override order is layout setting, then site setting, then package config default.
- Widgets and blocks declare frontend CSS/JS dependencies explicitly in PHP registration code. Layout regions may promote or demote loading strategy for the same widget above or below the fold.
- Runtime delivery should prefer small relevant CSS/JS files for HTTP/3, with optional profile bundling only if later metrics justify it.
- Replace manual theme-level critical CSS file paths with generated optimizer profile/page state and admin diagnostics.

## Architecture: Actions + Data (reach for these first)

**All domain logic in Actions** (`packages/{pkg}/src/Actions/`, suffix `VerbNounAction`):

- Single `handle()` method. Extend `Lorisleiva\Actions\Action` or use `AsObject`.
- Controllers, Filament pages, Livewire components, commands call `::run()` — no logic inside them.
- Validation via `rules()` / `authorize()` on the action. No parallel FormRequest.

**Structured data across boundaries** (`packages/{pkg}/src/Data/`, suffix `Data`):

- Inbound: `Data::from($request)` — no `$request->input()` munging.
- Outbound: API responses, Filament form state, Livewire wire-props.
- Model JSON columns cast via `AsData` / `AsDataCollection`. No bare arrays across layers.

**Typical slice:** Request → `InputData` → `SomeAction::run($input)` → `OutputData` → render.

## Filament conventions

- Enum options via backed enum implementing `HasLabel` — never inline option arrays.
- Translation keys for nav labels: `packages/admin/resources/lang/en/navigation.php`.

## Extension points (use these — don't hack core)

| Need                                                | How                                                                  |
| --------------------------------------------------- | -------------------------------------------------------------------- |
| Register page type / schema                         | `CapellCore::registerPageType\|registerSchema()`                     |
| Inject form fields                                  | Implement `PageSchemaExtender`, tag with `PageSchemaExtender::TAG`   |
| Lifecycle callbacks / validation gates              | `CapellAdmin::register()` / `subscribe()` / `ValidationSubscriber`   |
| Inject HTML into Blade                              | `RenderHookRegistry::register(RenderHookLocation::X, ...)`           |
| Package settings                                    | `SettingsSchemaRegistry::register()` + `registerSettingsClass()`     |
| Hook into static-site export                        | `StaticSiteExtensionRegistry::instance()->register($key, $callable)` |
| Subscribe to fine-grained lifecycle events          | `SubscriberManager::subscribe(SubscriberClass::class)`               |
| Add a custom admin toolbar action                   | Tag with `AdminToolItem::TAG`                                        |
| Programmatically register dashboard Filament widget | `CapellAdmin::registerDashboardFilamentWidget(WidgetClass::class)`   |
| Wire model changes to cache invalidation            | `CacheInvalidationRegistry::registerDependency($model, $patterns)`   |
| Register runtime frontend asset                     | `FrontendResourceRegistry` groups or `FrontendAssetContributor::TAG` |
| Cache an expensive Blade fragment                   | `@cache($key, $ttl, $surrogateKeys) ... @endcache` directive         |
| Extend Migration Assistant ownership maps           | `OwnershipMap::register($model, RelationOwnership::Owned)`           |
| Register Tailwind sources / imports                 | `TailwindAssetsRegistry::registerSource\|registerImport(...)`        |

Never use `php artisan capell:admin-publish-schemas` — it breaks upgrades.

## Database

- New core migrations must also be appended to `HasMigrations::getMigrations()` in `packages/core/src/Concerns/HasMigrations.php`.
- Settings migrations go in `database/settings/`, registered in `InstallCommand`, wrapped in `exists()` checks.
- Writes go through Actions, not model methods.

## Testing (Pest)

- Test actions directly: `MyAction::run($input)` — not through HTTP unless testing the HTTP surface.
- When testing commands, controllers, jobs, or UI classes that delegate to Actions, prefer Laravel Actions fakes (`AsFake`, `shouldRun()`, `shouldNotRun()`, `allowToRun()`) and assertions/expectations that prove the Action was invoked with the right arguments. Do not re-run expensive Action behavior through command tests when the Action already has direct coverage.
- Cross-system features: use real data (mocks miss configuration errors).
- Start with the narrowest useful Pest command, usually one test file or one package: `vendor/bin/pest packages/{package}/tests --configuration=phpunit.xml`.
- Minimum 90% coverage. Full suite: `composer test`.

## Admin Browser QA

- When browser-testing the Filament admin panel, do not stop at the login screen. Create or reuse a local test user in the application under test and assign the role being exercised, usually `super_admin` for full admin access.
- Prefer a clearly disposable QA account such as `codex-dashboard-qa@example.com`; set a known local-only password, assign the required role, clear permission/cache state if needed, then log in and verify the real admin UI.
- Use the narrowest role that proves the behaviour under test. If checking role-gated functionality, test both the allowed role and the denied role rather than assuming `super_admin` covers the path.

## Commands

| Command                  | Purpose                                   |
| ------------------------ | ----------------------------------------- |
| `composer test`          | Pest tests                                |
| `composer preflight`     | Changed-file formatting plus full PHPStan |
| `composer preflight:all` | Rector + full Pint + PHPStan + tests      |
| `composer lint`          | Pint only                                 |
| `composer analyze`       | PHPStan only                              |
| `composer prepare`       | Seed workbench                            |
| `composer serve`         | Build + serve localhost:8000              |

## Docker Harness

- Use the Docker harness when you need a clean, repeatable agent environment or when host PHP/Node/Composer state is suspect.
- It is the supported local package-development runtime for Capell package work. Do not recreate old `capell-app.test` host routing unless Ben explicitly asks for it.
- Start it with `docker compose up -d`; run checks with `docker compose exec app composer test`, `docker compose exec app composer analyze`, or a narrower package/file Pest command.
- For one-off tool checks without starting services, use `docker compose run --rm --no-deps app <command>`.
- The harness mounts this repo at `/home/capell/current`; add any external path repositories explicitly in the consuming app when package integration work needs them.
- The image file is `.docker/Dockerfile`. It may use a Debian-based PHP toolchain internally, but agents should treat that as an implementation detail and avoid adding distro-specific behaviour unless the toolchain requires it.

## Git

1. `composer test` — 100% pass before committing.
2. `composer preflight:all` — clean before committing when available.
3. Commit in small, reviewable slices at natural checkpoints after focused verification, especially when multiple agents are working on the same branch.
4. Prefer separate commits for distinct concerns such as schema, backend behaviour, admin UI, frontend output, docs, and tests.
5. Keep each commit self-contained and leave the working tree easy for another agent to rebase, inspect, or continue from.
6. Branch naming: `feat/`, `fix/`, `docs/`, `chore/`. Target: `4.x`.

## Package namespaces

`Capell\Core` · `Capell\Admin` · `Capell\Frontend` · `Capell\Installer` · `Capell\Marketplace`

Add-ons live outside this host monorepo. Treat installed package manifests and Composer metadata as the current source of truth because the package list changes often.

## Agent Speed

- Keep task branches focused. Large dirty trees across many packages slow agents down because they must preserve unrelated user work.
- Avoid long-running uncommitted work. Land small verified commits as soon as a coherent slice is complete so parallel agents have less state to preserve.
- Prefer package-level or file-level Pest runs during implementation; reserve `composer test`, `composer analyze`, and `composer preflight:all` for final verification.
- Avoid broad repo exploration when the target package or failing command is known. Start from the package, test, or class named in the request.
- Exclude heavy local paths from Spotlight/antivirus/indexing where practical: `vendor`, `node_modules`, `.git`, `storage`, `coverage`, `.phpunit.cache`, and framework/build caches.

## Key patterns

**Optional service binding:**

```php
private function draftHandler(): ?object
{
    return app()->bound('capell.workspace.page-draft-handler')
        ? app('capell.workspace.page-draft-handler')
        : null;
}
```

**Tagged extender query:**

```php
collect(app()->tagged(PageTableExtender::TAG))
    ->reduce(fn (Builder $carry, PageTableExtender $ext) => $ext->modifyQuery($carry), $query)
```

## Imported Claude Cowork project instructions

CMS package for Filament. Split into independent `admin` and `frontend` packages, with a shared `core` package.
