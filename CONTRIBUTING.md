# Contributing to Capell

Thanks for helping improve Capell. This guide covers the workflow, coding standards, and test expectations for changes to this monorepo.

## Quick start

1. Fork and clone the repository.
2. Install PHP and Node dependencies:
    ```bash
    composer install
    npm install
    ```
3. Run the test suite:
    ```bash
    composer test
    ```
4. Create a feature branch and commit your changes following the conventions below.

## Branch and commit conventions

- Prefix branches with a type: `feat/`, `fix/`, `docs/`, `chore/`, `refactor/`, `test/`.
- Keep commits concise, imperative, and scoped: `feat(admin): add schema cache refresh option`.
- Squash minor fixup commits before opening a PR.

## Coding standards

- Read the [full coding standard](docs/standards/coding-standards.md) before changing production code or package extension points.
- PHP: 8.4+. Use strict types.
- Style: PSR-12 plus project rules, enforced via Laravel Pint.
    ```bash
    composer lint
    ```
- Static analysis: run PHPStan (configuration in `phpstan.neon`).
    ```bash
    composer analyze
    ```
- Rector: if a refactor needs new rules, configure them in `rector.php` and run locally. Never commit bulk unrelated changes.
- Mark concrete classes `final` unless they are documented extension points. Keep documented extension surfaces open and stable.

## Tests

- Framework: Pest.
- Location: package tests live under `packages/{package}/tests`; repository-level tests live under `tests/` when the behaviour crosses package boundaries.
- For new commands or services, include a happy-path test and at least one edge case (invalid option, missing data, and so on).
- Use factories and the provided test helpers; avoid hitting external services.
- Keep tests deterministic — no reliance on real time unless you use `Carbon::setTestNow()`.

## Adding a page type or admin configurator

1. Register the type via a service provider using `CapellCore::registerPageType(new PageTypeData(...))`.
2. Add or extend a configurator under the owning package's `Filament/Configurators` namespace.
3. Register configurators through Capell admin registration surfaces or `CapellAdminPlugin::discoverConfigurators(...)` in a consuming app.
4. Add focused tests for discovery, form fields, and any action behaviour behind the UI.

Do not publish and edit generated admin schemas as an upgrade strategy. Extension packages should use documented registries, tagged extenders, lifecycle subscribers, and configurators instead of patching host package classes.

## Frontend output, cache, and sitemap changes

If you are adjusting public rendering, cache generation, sitemap generation, or static output:

- Add tests proving anonymous and non-admin responses do not expose authoring markers, signed admin URLs, model IDs, selectors, or package internals.
- Keep data loading out of public Blade views; hydrate render data before the view is called.
- Keep invalidation targeted. Avoid broad cache purges unless they are intentional and documented.

## Performance considerations

- Avoid N+1 queries in admin widgets and resources; use eager loading.
- Cache heavy computed lists where appropriate using existing facade abilities.
- Consider pagination for large result sets in Filament tables.

## Security

- Never commit secrets or `.env` files.
- Validate and authorise actions in new controllers and commands with appropriate policies and gates.
- Escape output in Blade unless you are intentionally rendering trusted HTML.

## Documentation

- Update `README.md` for user-facing additions (commands, env vars, extension points).
- Add new docs under `docs/` for deep dives, and link them from the right hub page so they stay reachable — [docs/development/docs-ownership.md](docs/development/docs-ownership.md) explains where each page belongs.
- Keep examples minimal but runnable.
- The hosted docs at [docs.capell.app](https://docs.capell.app) rebuild automatically when a release is published — no manual step needed.

## Release and changelog

- Do not edit `CHANGELOG.md` by hand. A workflow (`.github/workflows/update-changelog.yml`) regenerates it from GitHub release notes when a release is published, and it overwrites manual edits. Write clear PR titles and release notes instead — that is what ends up in the changelog.
- Follow semantic versioning for published packages.

## Local checks

Run these before opening a PR — they mirror what CI runs:

- `composer test` — the Pest suite.
- `composer lint` — Pint on changed files.
- `composer analyze` — PHPStan.
- `composer preflight` — the full gate: static analysis, Rector check, code style, Prettier, ESLint, and the test preflight.
- For documentation changes, the docs guards: `composer check:docs-links`, `check:docs-orphans`, `check:docs-requirements`, `check:docs-env`, and `check:root-docs`. `composer preflight:all` runs all of them plus everything above.

## Getting help

Open a GitHub Issue with reproduction steps and environment details.

## Package independence

- Core MUST NOT depend on Admin or Frontend packages.
- Admin and Frontend MUST remain independent of each other and of Core internals beyond documented public interfaces.
- Do not import or reference classes across packages (e.g., no `use Capell\Admin\...` from Core).
- Cross-package coordination must use neutral boundaries:
    - Emit events or subscriber hooks via `CapellCore::subscriberManager()`.
    - Use shared cache/filesystem paths or Artisan command names (strings) without importing package classes.
- When Core needs to trigger behaviour in another package (e.g., clear Admin schemas cache), emit hook events only (e.g., `admin.schemas.clearing` / `admin.schemas.cleared`). The target package implements the actual behaviour via a subscriber.
- Treat any static analysis errors caused by cross-package references as blockers; fix imports and case names rather than suppressing.

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

Thanks for contributing.
