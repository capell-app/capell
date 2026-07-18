# Core facade and registry redesign

## Status

Pre-release proposal only. This document does not authorize runtime changes in the standards pass.

## Goals

- Replace the 86-method `CapellCore` surface with cohesive, typed capabilities.
- Align registry vocabulary so extension authors can predict how every registry behaves.
- Prefer constructor-injected collaborators in domain code while retaining narrow facades at framework boundaries.
- Replace generic exceptions, raw configuration reads, and fat parameter lists incrementally without compatibility shims before 1.0.

## Facade split

Keep `CapellCore` as the compatibility entry point during the migration, delegating to three focused capabilities:

- `packages()`: package discovery, requirements, lifecycle state, page types, and variations.
- `components()`: component, model interceptor, model relation, subscriber, and protected-table registries.
- `cache()`: remembered values, local cache state, cache-key tracking, and invalidation.

Application and package domain code should inject the capability interface directly. Service providers, Blade integration, and extension bootstrap code may use the focused facades where container injection is awkward.

## Registry vocabulary

Adopt the same verbs everywhere:

- `register(...)`: add or replace one definition and return `void` unless fluent composition is demonstrably used.
- `get(key)`: return one definition or throw the registry's typed not-found exception.
- `find(key)`: return one definition or `null`.
- `has(key)`: report membership.
- `all()`: return every definition in stable registration order.
- `remove(key)` and `clear()`: explicit mutation operations.

Rename divergent reads such as `getSchema`, `definition`, `provider`, and `forModel` to `get`; rename plural accessors such as `definitions` to `all`. Do not mix `self` and `void` registration returns across registries.

## Static and instance normalization

Convert `HasMigrations` and `HasModelRelations` to instance-owned registries resolved from the container. Static mutation makes test isolation and Octane reset behavior implicit. Each mutable registry must either implement `Resettable` or be request-scoped.

Package lifecycle writes should live behind one repository. Replace the six `markPackage*` setters and the `isPackageInstalled`, `isPackageEnabled`, and `isPackageAvailable` predicates with explicit lifecycle transitions and a single status value object.

## Single-adapter seams

Review interfaces with exactly one production implementation:

- `PageCreatable`
- `AdminResourceResolver`
- `RedirectUrlRecorder`

Keep an interface only when packages replace it, tests require a materially different fake, or the boundary protects core from an optional package. Otherwise inject the concrete class or replace the seam with a small callable at the integration point.

## Typed inputs

Replace fat Action signatures with immutable input data:

- `PrepareInstallApplicationAction`: one install-preparation input instead of eight parameters and three booleans.
- `CreateThemeAction` and `BuildThemeMetadataAction`: a shared `ThemeDefinitionInput` composed from the existing `Data/Theme/*` value objects.

Inputs own validation and normalization; Actions own orchestration and writes.

## Exceptions

Inventory the 127 generic throws and migrate bounded domains first. Use typed exceptions with named constructors, following `ExtensionRegistrationException::forPageType()`. Exception messages remain stable until callers and tests have migrated. Avoid a single catch-all core exception hierarchy.

## Typed configuration

Introduce small readers for repeated, structurally important keys rather than wrapping all 140 `config()` calls. Start with:

- `auth.providers.users.model`
- `capell-core.cache_tag`

Readers validate once, return exact types, and provide the existing defaults. Package-local, one-off configuration reads can remain direct.

## Events

Choose `Event::dispatch(...)` for framework-facing publication and injected dispatchers where domain tests need isolation. Migrate the current `event()`, `Event::dispatch()`, and static `::dispatch()` mix by bounded domain; do not sweep mechanically.

## Data immutability

Do not add a blanket readonly rule to Spatie Data classes. `PackageData` and `DoctorCheckResultData` currently mutate after construction, and lazy/wither behavior must be proven first. Promote individual data classes only after their mutation contracts are removed and covered.

## Sequence

1. Add capability interfaces and characterization tests around the existing manager.
2. Move cache and component registries first; they have the clearest ownership.
3. Introduce the package lifecycle repository and status value object.
4. Migrate core consumers to dependency injection.
5. Publish focused facades for package bootstrap use.
6. Rename registry verbs and remove the legacy manager surface before 1.0.
7. Address typed inputs, exceptions, configuration readers, and event style in separate reviewed waves.

Each wave must preserve multi-site isolation, localisation, Octane reset behavior, extension registration order, cache/static-export contracts, and anonymous frontend output safety.
