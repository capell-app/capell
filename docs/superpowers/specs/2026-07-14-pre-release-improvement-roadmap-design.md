# Capell Pre-Release Improvement Roadmap Design

**Date:** 2026-07-14
**Status:** Approved for planning
**Source:** Capell Pre-Release Improvement Roadmap supplied on 2026-07-14

## Purpose

Capell's first public release is blocked on release confidence rather than additional broad capability. The work must make the assembled foundation packages, curated companion packages, and consuming application predictably safe, diagnosable, and reproducible.

This design turns the roadmap into six ordered implementation plans. Each plan must leave a testable increment, preserve useful foundations already merged, and remove pre-release contracts that conflict with the target architecture. Passing package-only suites is necessary but never sufficient evidence for release readiness.

## Current-state baseline

The 2026-07-14 four-phase hardening plan is already merged. It provides:

- `PublishVisibilityStateEnum`, `PublishSentinel`, and initial enum/scope/UI parity work.
- A provisional global fragment-builder seam and Layout Builder/Marketing implementations.
- Core-owned patch primitives and package lifecycle registration.
- A first populated Demo/Install Health report.
- Existing release smoke and companion-package release-confidence tooling.

The new roadmap supersedes the provisional global fragment builder, expands publication classification into an authoritative mutation boundary, makes Marketplace consent authoritative, follows translation ownership for cache invalidation, formalizes diagnostics, and requires a pinned cross-repository solve. Implementations must extend the useful foundations rather than repeat them.

## Ordering and gates

The plans execute in this order:

1. Public fragment security and ownership.
2. Authoritative publishing state machine.
3. Marketplace policy and cache invalidation authority.
4. Diagnostics and non-mutating verification.
5. Cross-repository release-confidence harness.
6. Post-gate developer and editor product improvements.

Plans 1–4 establish contracts consumed by Plan 5. Plan 5 is the first-release gate. Plan 6 can be developed in parallel only when its changes do not weaken or bypass the gate; no public release can be approved until Plan 5 proves all P1/P2 requirements.

## Architecture decisions

### 1. Public fragments

The Frontend package owns a shared encrypted transport, not a shared owner implementation. The transport consists of:

- A typed, versioned envelope containing owner, page/site/language identity, content version, and owner payload.
- A codec that encrypts/decrypts the envelope and converts all malformed input into a generic not-found outcome.
- A registry of explicit owner-to-URL-resolver bindings.
- A public-context Action that reloads authoritative records and validates publication, ownership, language, layout, and content version before HTML is rendered.

Layout Builder and the consuming application's Marketing surface each register distinct owners and routes. The registry never infers an owner from payload shape. The legacy global fragment-builder contract is deleted without an adapter.

### 2. Publishing

Core owns one typed publication transition boundary because date normalization and state partitioning are foundation invariants. Requests carry the record, transition, requested timestamps, and actor context. Results use stable outcome values: changed, already-correct, unauthorized, invalid-transition, and failed.

Admin single-record and bulk Actions become adapters around the Core boundary. All reporting and UI state consumers derive from `PublishVisibilityStateEnum` and mutually exclusive Core scopes. Bulk UI performs the same dry-run evaluation used by execution, so preview and notification counts cannot diverge.

### 3. Marketplace and cache authority

Marketplace installation begins with a typed request and a fresh listing fetch. `InstallMarketplaceExtensionAction` is the only policy boundary and validates maturity, transitive maturity, entitlement, compatibility, and explicit beta acknowledgement before any acquisition or Composer work. The install-attempt ledger stores the decision evidence even when blocked.

Frontend cache invalidation resolves the changed object's owner before traversing dependencies. Translation changes first resolve pageable, media, site, or registered extension ownership, then the registry/executor produces deduplicated rules. Models and Filament components remain free of cache policy.

### 4. Diagnostics and verification

Doctor findings gain stable IDs, native severity, structured evidence, and remediation. A Core schema catalog is the only required-table source for both installation-state resolution and doctor checks. Installation state is one of not installed, partial, or installed, based on schema footprint plus the `capell_extensions` lifecycle record for Core.

The Operations Center renders findings from these stable values and exposes an explicit re-run action. Admin access is proven using configured user model, role model, guard, and real Filament panel access.

Composer check commands are non-mutating by contract. `preflight:all` runs Rector dry-run and Pint check; mutation is available only through `preflight:fix`. A script-level test protects this distinction.

### 5. Release confidence

The real consuming application at `/Users/ben/Sites/capell-app` is the golden integration fixture. A release manifest pins the Core monorepo, companion monorepo, and consuming app SHAs plus the curated package set and supported PHP/Laravel/Filament matrix.

The harness creates clean temporary checkouts, configures path repositories, performs a fresh Composer solve, installs without prompts, and exercises the product-level contracts introduced by Plans 1–4. Existing package suites remain independently runnable. Existing release-confidence tooling is extended or moved deliberately; it is not duplicated under a second competing command.

### 6. Developer and editor product

Stable, experimental, and internal extension surfaces are catalogued from executable metadata. A reusable companion-package contract test kit extends the existing Core test harness. Stable API snapshots begin only after the first public release.

The Admin product makes publish readiness and Operations Center findings the primary editor/operator surfaces. Marketplace review shows direct and transitive operational impact. Accessibility readiness covers required translations and localized media intent. Advanced collaboration remains owned by Publishing Studio.

## Cross-repository ownership

| Repository | Ownership |
| --- | --- |
| `/Users/ben/Sites/packages/capell/capell-4` | Core invariants, Frontend transport/cache, Admin UI/reports, Marketplace install boundary, foundation tests and CI |
| `/Users/ben/Sites/packages/capell/capell-packages-4` | Layout Builder fragment owner, companion-package contract coverage, Publishing Studio integration, package release matrix |
| `/Users/ben/Sites/capell-app` | Marketing fragment owner, golden consuming-app fixture, real admin/homepage smoke, release manifest orchestration |

Implementation work must use isolated worktrees because the companion repository currently contains unrelated user changes. No plan authorizes modifying or cleaning those changes.

## Failure behavior

- Public fragment failures return a generic 404 and never reveal which validation failed.
- Publication transitions return typed outcomes; expected invalid/unauthorized outcomes are not exceptions.
- Marketplace policy failures are recorded before returning a blocked result.
- Cache traversal deduplicates visited owners and rules to prevent recursion.
- Partial installations produce critical findings rather than empty or healthy reports.
- Release harness failures retain command, repository SHA, matrix coordinates, and artifact paths while redacting secrets.

## Verification strategy

Every implementation task follows red-green-refactor discipline and commits a coherent increment. Verification layers are:

1. Narrow Action/DTO/registry unit tests.
2. Package integration tests for actual container bindings and persistence.
3. Cross-package contract tests in the monorepos.
4. Real consuming-app feature/browser smoke.
5. Clean temporary-checkout Composer solve and install matrix.
6. A clean-tree assertion around all documented check commands.

Release approval requires direct evidence for every roadmap acceptance scenario and no open P1/P2 finding. Generated reports and manifests are evidence only when their producing checks cover the relevant contract.

## Explicit non-goals

- No backward-compatibility adapter for pre-release fragment or duplicate patch contracts.
- No new migration solely to represent installation state.
- No database queries or lazy loading from public Blade/theme output.
- No cache invalidation policy in models, observers beyond dispatch, or Admin components.
- No approvals, assignments, release workspaces, or comments moved from Publishing Studio into Core/Admin.
- No public stable-API compatibility promise before the first release.

## Plan deliverables

The implementation set is defined by these documents:

- `2026-07-14-public-fragment-security.md`
- `2026-07-14-publishing-state-machine.md`
- `2026-07-14-marketplace-policy-cache-correctness.md`
- `2026-07-14-diagnostics-release-verification.md`
- `2026-07-14-cross-repository-release-confidence.md`
- `2026-07-14-developer-editor-product.md`

Each plan contains repository ownership, exact files, test-first tasks, commands, expected outcomes, commit boundaries, and an exit gate.
