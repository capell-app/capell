# Contributing to Capell

Thanks for your interest in improving Capell. This guide outlines workflow, coding standards, and test expectations for changes to this monorepo.

## Quick Start

1. Fork & clone the repository.
2. Provision the checkout. The supported runtime is PHP 8.4, and the normal route is the repository's Docker harness, driven by the `./capell` wrapper:
    ```bash
    ./capell up -d
    ./capell composer install
    npm ci --no-audit --no-fund
    ```
    If you run PHP 8.4 on the host instead, use `composer install` and `npm ci --no-audit --no-fund` directly. Do not treat failures from another host PHP version as release evidence.
3. Run a focused test, then the standard gate:
    ```bash
    ./capell pest packages/core/tests/path/ToTest.php --configuration=phpunit.xml
    composer preflight
    ```
    `./capell test` runs the suite and takes no path; `composer test` is the full host suite.
4. Create a feature branch and commit changes following conventions.

### Installing in sandboxed or proxied environments

Some environments (for example Claude Code cloud containers or locked-down CI proxies) return `403` for GitHub zipball downloads (`api.github.com` / `codeload.github.com`) while `git clone` still works. A plain `composer install` then fails, and `--prefer-source` alone is not enough because some lock entries are dist-only — notably `phpstan/phpstan`, which fails with "Could not authenticate against github.com".

Use the source-install script instead:

```bash
scripts/install-from-source.sh
```

It sets `use-github-api false` globally, temporarily adds a git `source` (same commit reference) to any dist-only GitHub lock entry — the `phpstan/phpstan` repository ships `phpstan.phar` itself, so a clone at the locked reference is equivalent to the dist — runs `composer install --prefer-source`, and restores `composer.lock` byte-for-byte on exit, including on failure. Never commit a `composer.lock` modified by this workaround; `git status` should show it clean afterwards. Extra arguments are passed to `composer install`.

The codebase requires PHP 8.4 (it uses syntax such as `new X()->method()`); PHP 8.3 fails to parse it. In Claude Code cloud sessions, `.claude/hooks/session-start.sh` installs PHP 8.4 (via `ppa:ondrej/php`) when needed and runs the script if `vendor/` is missing.

### Docker harness and worktrees

- `./capell` derives a per-checkout Compose project and runs Composer, Pest and Pint as the `capell` user with the expected test environment. Prefer it over `docker compose exec` directly, which runs as root and trips the package database guard.
- Use a [git worktree](docs/development/worktrees.md) when more than one person or agent session works in this repository at once, and provision it fully before the first gate.
- Use [Composer path repositories](docs/development/local-development.md) to test local package changes from a consuming Laravel application.
- Broad test and preflight commands share the [release verification gate](docs/release-verification-lock.md); a run started during a release waits for it.
- [Local hazards](.agents/docs/08-local-hazards.md) lists the known traps (hybrid `vendor/` symlinks, host and container PHPStan caches, path-scoped `composer analyze`).

## Branch & Commit Conventions

- Prefix branches with type: `feat/`, `fix/`, `docs/`, `chore/`, `refactor/`, `test/`.
- Commits should be concise, imperative, and scoped: `feat(admin): add schema cache refresh option`.
- Squash minor fixup commits before opening a PR.

## Coding Standards

- Read the [full coding standard](docs/standards/coding-standards.md) before changing production code or package extension points.
- PHP: 8.4+. Use strict types.
- Style: Laravel Pint with the `laravel` preset plus the project rules in `pint.json`.
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
- Location: package tests live under `packages/{package}/tests`; repository-level tests live under `tests/` when the behaviour crosses package boundaries.
- For new commands or services, include:
    - Happy path test.
    - At least one edge case (invalid option, missing data, etc.).
- Use factories / provided test helpers; avoid hitting external services.
- Ensure tests are deterministic (no reliance on real time unless using Carbon::setTestNow()).

## Adding A Page Type Or Admin Configurator

1. Register Type via a service provider using `CapellCore::registerPageType(new PageTypeData(...))`.
2. Add or extend a configurator under the owning package's `Filament/Configurators` namespace.
3. Register configurators through Capell admin registration surfaces or `CapellAdminPlugin::discoverConfigurators(...)` in a consuming app.
4. Add focused tests for discovery, form fields, and any action behaviour behind the UI.

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
- Validate/authorise actions in new controllers/commands with appropriate policies / gates.
- Escape output in Blade unless intentionally rendering trusted HTML.

## Documentation

- Update `README.md` for user-facing additions (commands, env vars, extension points).
- Add new docs under `docs/` for deep dives and link them from the narrowest relevant index (`docs/README.md` or a section index). The [docs ownership rules](docs/development/docs-ownership.md) say where a page belongs.
- Keep examples minimal but runnable.
- After a docs edit run `composer docs:check`; it chains the link, orphan, requirements, command, env, config and screenshot guards that CI runs.
- The hosted docs at [docs.capell.app](https://docs.capell.app) rebuild automatically when a release is published — no manual step needed.

## Release & Changelog

- Update the `Unreleased` section of `CHANGELOG.md` under the matching heading: Added, Changed, Fixed, Deprecated, Removed. Published releases are appended by the `Update Changelog` workflow.
- Follow semantic versioning for published packages.

## CI Hints

- `composer preflight` runs the standard static, contract, formatting, analysis and test gates; `composer preflight:all` adds the doc guards, Rector, Prettier, ESLint and the security audit, and applies Rector and Pint fixes, so review its diff.
- Hosted CI runs the same gates; a ready pull request runs the Fast PR gate shards and the Test All matrix cells, and all of them must pass (see [CI and test shards](docs/development/ci.md)).

## Getting Help

Open a GitHub Issue with reproduction steps and environment details, or visit https://capell.app/support for additional help.

## Package Independence

- Core MUST NOT depend on Admin or Frontend packages.
- Admin and Frontend MUST remain independent of each other and of Core internals beyond documented public interfaces.
- Do not import or reference classes across packages (e.g., no `use Capell\Admin\...` from Core).
- Cross-package coordination must use neutral boundaries:
    - Emit events or subscriber hooks via `CapellCore::subscriberManager()`.
    - Use shared cache/filesystem paths or Artisan command names (strings) without importing package classes.
- When Core needs to trigger behaviour in another package (e.g., clear Admin schemas cache), emit hook events only (e.g., `admin.schemas.clearing` / `admin.schemas.cleared`). The target package implements the actual behaviour via a subscriber.
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
