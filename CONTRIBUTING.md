# Contributing to Capell

Thanks for your interest in improving Capell. This guide outlines workflow, coding standards, and test expectations for changes to this monorepo.

## Quick Start

1. Fork & clone the repository.
2. Install PHP & Node dependencies:
    ```bash
    composer install
    npm install
    ```
3. Run the test suite:
    ```bash
    composer test
    ```
4. Create a feature branch and commit changes following conventions.

## Branch & Commit Conventions

- Prefix branches with type: `feat/`, `fix/`, `docs/`, `chore/`, `refactor/`, `test/`.
- Commits should be concise, imperative, and scoped: `feat(admin): add schema cache refresh option`.
- Squash minor fixup commits before opening a PR.

## Coding Standards

- PHP: 8.4+. Use strict types.
- Style: PSR-12 + project rules enforced via Laravel Pint.
    ```bash
    composer lint
    ```
- Static Analysis: Run PHPStan (configuration in `phpstan.neon`).
    ```bash
    composer analyze
    ```
- Rector: If refactors are needed, configure rules in `rector.php` and run locally; never commit bulk unrelated changes.
- Use `final` for concrete classes that are not intended as extension points. Keep documented extension surfaces open and stable.

## Tests

- Framework: Pest.
- Location: package tests live under `packages/{package}/tests`; repository-level tests live under `tests/` when the behavior crosses package boundaries.
- For new commands or services, include:
    - Happy path test.
    - At least one edge case (invalid option, missing data, etc.).
- Use factories / provided test helpers; avoid hitting external services.
- Ensure tests are deterministic (no reliance on real time unless using Carbon::setTestNow()).

## Adding A Page Type Or Admin Configurator

1. Register Type via a service provider using `CapellCore::registerPageType(new PageTypeData(...))`.
2. Add or extend a configurator under the owning package's `Filament/Configurators` namespace.
3. Register configurators through Capell admin registration surfaces or `CapellAdminPlugin::discoverConfigurators(...)` in a consuming app.
4. Add focused tests for discovery, form fields, and any action behavior behind the UI.

Do not publish and edit generated admin schemas as an upgrade strategy. Extension packages should use documented registries, tagged extenders, lifecycle subscribers, and configurators instead of patching host package classes.

## Frontend Output, Cache, And Sitemap Changes

If adjusting public rendering, cache generation, sitemap generation, or static output:

- Add tests proving anonymous and non-admin responses do not expose authoring markers, signed admin URLs, model IDs, selectors, or package internals.
- Keep data loading out of public Blade views; hydrate render data before the view is called.
- Ensure invalidation remains targeted. Avoid broad cache purges unless they are intentional and documented.

## Performance Considerations

- Avoid N+1 queries in admin widgets/resources; use eager loading.
- Cache heavy computed lists where appropriate using existing facade abilities.
- Consider pagination for large result sets in Filament tables.

## Security

- Never commit secrets or `.env` files.
- Validate/authorize actions in new controllers/commands with appropriate policies / gates.
- Escape output in Blade unless intentionally rendering trusted HTML.

## Documentation

- Update `README.md` for user-facing additions (commands, env vars, extension points).
- Add new docs under `docs/` for deep dives (link them from README Next Steps section).
- Keep examples minimal but runnable.
- The hosted docs at [docs.capell.app](https://docs.capell.app) rebuild automatically when a release is published — no manual step needed.

## Release & Changelog

- Update `CHANGELOG.md` with summary: Added, Changed, Fixed, Deprecated, Removed.
- Follow semantic versioning for published packages.

## CI Hints

- Include running: `composer validate`, Pint, PHPStan, Pest.
- Optional: cache Composer & npm to speed builds.

## Getting Help

Open a GitHub Discussion or Issue with reproduction steps and environment details.

## Package Independence

- Core MUST NOT depend on Admin or Frontend packages.
- Admin and Frontend MUST remain independent of each other and of Core internals beyond documented public interfaces.
- Do not import or reference classes across packages (e.g., no `use Capell\Admin\...` from Core).
- Cross-package coordination must use neutral boundaries:
    - Emit events or subscriber hooks via `CapellCore::subscriberManager()`.
    - Use shared cache/filesystem paths or Artisan command names (strings) without importing package classes.
- When Core needs to trigger behavior in another package (e.g., clear Admin schemas cache), emit hook events only (e.g., `admin.schemas.clearing` / `admin.schemas.cleared`). The target package implements the actual behavior via a subscriber.
- Treat any static analysis errors caused by cross-package references as blockers; fix imports/case names rather than suppressing.

### Sanctioned exception: event sourcing in Core

Core hosts the event-sourcing engine (`spatie/laravel-event-sourcing`), aggregates,
projectors, and reactors under `Capell\Core\EventSourcing`. This is a deliberate,
sanctioned relaxation of "Core must not depend on opinionated packages", made because
the event store is the single append-only source of truth for editorial workflow and
multi-relation page rollback, and must live alongside the models it records.

The package boundary still holds in the cross-package direction:

- Admin depends on Core's event-sourcing seam (the `EventSourcedRegistry`, the rollback
  actions, the neutral `Rollback\*Data` DTOs), never the reverse. Core never imports Admin —
  the rollback preview exposes package-neutral diff data that Admin maps onto its own
  activity-diff renderer.
- Core reaches other packages only through `CapellCore::subscriberManager()` (see the
  `ListenerEnum` page-workflow hooks emitted by `PageWorkflowReactor`), never by importing them.
- Models opt in with the `IsEventSourced` trait; new adopters register their aggregate +
  serializer with the `EventSourcedRegistry` and never touch the engine itself.

Known limitations:

- Rollback restores tree position via `parent_id` + `order`, but sibling nested-set
  rebalancing is out of aggregate scope.
- The editorial workflow ships backend-only: status is projected and shown as a
  read-only badge, but there are no editor-facing transition controls. The
  `page.workflow.manage` permission is intentionally not registered until a UI that
  enforces it lands — the full publishing workflow is owned by
  `capell-app/publishing-studio`, not core.
- Rollback does not reconcile `PageWorkflowState.status` against the restored
  visibility. Deriving a status from visibility alone is unsafe (it would resurrect
  archived pages and cannot express review-intent states), so the read-model status
  is left as-is pending a product decision on the intended post-rollback state.
- The history index is forward-only: a page records its first revision on its next
  save after adopting event sourcing, so pre-existing pages show empty history until
  then. There is no backfill.

Thanks for contributing! Your improvements help keep Capell fast, extensible, and a joy to build on.
