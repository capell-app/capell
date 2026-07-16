# Capell coding standards

These standards describe the shapes already used by the strongest and most common code in the Capell host monorepo. They apply to `packages/`, repository-level `tests/`, and supporting scripts. Package-specific conventions may add constraints, but they must not weaken these rules.

The priority order is:

1. Preserve observable behaviour and public compatibility.
2. Preserve package and public-render boundaries.
3. Make invalid states explicit through types and focused validation.
4. Match the established local shape.
5. Prefer mechanical enforcement over review-only guidance.

A consistency change must not rename a public class, route, translation key, config key, Blade component, event, command, or persisted field unless the change is explicitly treated as a compatibility migration. A standards pass is not permission to alter those contracts.

## Evidence behind the standard

The July 2026 standards inventory covered the following surfaces before rules were selected:

| Surface                                         |        Repository population |                                     Representative sample | Dominant findings                                                           |
| ----------------------------------------------- | ---------------------------: | --------------------------------------------------------: | --------------------------------------------------------------------------- |
| PHP under `packages/`, `tests/`, and `scripts/` |                  3,451 files |                                     repository-wide check | 100% use `declare(strict_types=1)`                                          |
| Actions                                         |                  405 classes |                               24 across all host packages | verb-led operations, `handle()`, explicit returns, explicit `AsObject` + `AsFake` traits |
| Support code                                    |                    373 files |                               25 across all host packages | focused collaborators, typed APIs, early returns                            |
| Enums                                           |                    149 enums |                               20 across all host packages | singular concepts; suffix usage is split and therefore compatibility-owned  |
| Livewire components                             |                    9 classes |                                                     all 9 | typed public state and authorization at mutations                           |
| Blade views                                     |                    189 views | 21 across public, admin, installer, and marketplace views | kebab-case component paths, pre-hydrated public data, Tailwind-first markup |
| Migrations                                      |                     49 files |                               24 across all host packages | anonymous classes, typed `up()`/`down()`, snake-case schema names           |
| Pest tests                                      | 1,180 PHP test/support files |                30 across all host packages and root tests | `it('...')`, factories/helpers, deterministic setup                         |

Not every majority is safe to enforce retroactively. Only about 64% of Actions are final, and enum names are split between semantic names and an `Enum` suffix. New code follows the defaults below, while existing extension and compatibility surfaces remain unchanged until a dedicated migration is justified.

## PHP style beyond Pint

### Strict types and declarations

Every PHP file starts with `declare(strict_types=1);`. Pint enforces this repository-wide. Do not add a local exception.

Use native parameter, property, constant, and return types wherever PHP can express the contract. Use PHPDoc to add information PHP cannot express, especially collection key/value types and array shapes; do not duplicate a native type with a less precise docblock.

`packages/frontend/src/Actions/AssertPublicRenderPerformanceBudgetAction.php` is the reference shape for a small typed Action. `packages/frontend/src/Data/Assets/FrontendResourceData.php` is the reference for a structured Data boundary.

### Constructor promotion and readonly state

Promote constructor dependencies and immutable Data properties when the promoted declaration is clearer than separate property boilerplate:

```php
public function __construct(
    private readonly RuntimeSchemaState $schemaState,
) {}
```

This is the shape used by `packages/admin/src/Actions/Reports/BuildPublicRenderSafetyReportAction.php`.

Use `readonly` for injected dependencies and value-object state that must not be reassigned. Do not mark Eloquent models, Livewire components, Filament resources, or framework-hydrated properties readonly.

### Final and extension points

Concrete classes are `final` by default when Capell owns construction and subclassing is not a supported seam. Leave a class open only when one of these is true:

- downstream packages are expected to subclass it;
- Filament, Livewire, Eloquent, or a test double needs inheritance;
- an abstract/template-method hierarchy deliberately owns the variation;
- the class is already a public extension surface and sealing it would be a compatibility break.

Do not mass-apply `final`. Existing open classes are reviewed individually because the current repository is below the threshold for mechanical enforcement.

### Named arguments

Use positional arguments when a call is short and the meaning is obvious. Use named arguments when a constructor or method has several same-typed parameters, optional flags, or a public structured boundary. Once one optional argument is skipped, name the remaining arguments.

`packages/admin/src/Actions/Reports/BuildPublicRenderSafetyReportAction.php` uses named arguments for report Data objects. `packages/frontend/src/Data/Assets/FrontendResourceData.php` uses positional arguments inside its tightly controlled named factories; callers should normally use those factories.

Never use named arguments as decoration on every one-argument call. Public parameter names are compatibility surface, so rename one only through an explicit compatibility review.

### Control flow

Prefer early returns for invalid, absent, unauthorized, or already-complete states. Keep the successful path at the lowest indentation level. A branch that represents a domain decision may remain explicit even when it could be compressed into a ternary.

`BuildPublicRenderSafetyReportAction::handle()` returns the missing-table result before running report queries. `PublicViewQueryGuard::guard()` returns immediately when the guard is disabled or the audience is not public.

### Action, Support class, or private method

Use an Action when the operation:

- expresses a domain or application verb;
- is called from more than one delivery mechanism;
- needs direct behavioural tests, authorization, transaction ownership, queueing, or orchestration;
- is a stable operation packages may call.

Actions use a verb-led singular name and the `Action` suffix, expose one public `handle()` operation, and compose `AsObject` with `AsFake` where static `run()` is part of the call shape. `AsAction` is prohibited because it adds controller, command, listener, and job adapters that a domain Action may not need. Add a granular adapter trait only when its corresponding entry point is actually used. Production and test callers invoke Actions through `::run(...)`, never by resolving, constructing, or injecting an Action solely to call `handle(...)`. Constructor injection is preferred for collaborators, and tests fake downstream Actions with `::shouldRun()`, `::shouldNotRun()`, `::mock()`, or `::spy()`. Public lifecycle Actions that predate the suffix rule remain compatibility exceptions.

Use a Support class for a cohesive capability, policy, resolver, registry, adapter, or stateful collaborator with more than one meaningful operation. `packages/frontend/src/Support/Render/PublicViewQueryGuard.php` is a Support class because it owns query-capture state and several cohesive policies.

Keep logic as a private method when it is meaningful only to one class, has no independent boundary, and extracting it would expose implementation detail. Extract duplicated logic only after at least three real call sites or when a security/correctness boundary needs one owner.

## Type discipline and PHPStan

- PHPStan remains at the configured level; lowering the level or coverage threshold is not a fix.
- No property, parameter, or return is left untyped unless a framework-owned signature prevents it. Add the narrowest truthful PHPDoc in that case.
- Arrays use shapes for fixed records and `list<T>` or `array<TKey, TValue>` for collections.
- Laravel and Eloquent collections declare both key and model/value types.
- Generic interfaces are always supplied with their template types at use sites.
- Data received from HTTP, config, cache, JSON, or a package boundary starts as `mixed` and is narrowed before domain use.
- A PHPDoc assertion must describe a runtime fact established by adjacent validation; never silence PHPStan with a type that can be false at runtime.

`packages/marketplace/src/Data/ExtensionListingData.php` demonstrates typed immutable boundary data and typed collection properties. `packages/admin/src/Filament/Resources/Pages/Tables/PagesTable.php` demonstrates `Builder<Model>`, Eloquent collection, and list annotations.

The PHPStan ignore file is a debt ledger, not a destination:

- never add a broad package/path ignore for a new error;
- never increase an existing count to land a change;
- prefer correcting the native type, generic, or local narrowing;
- every inline `@phpstan-ignore` includes the error identifier and a same-line reason describing the framework or runtime fact PHPStan cannot see;
- durable debt also links to an upstream issue or Capell decision document when one exists;
- delete an ignore in the same commit that fixes its last matching error.

## Naming

### PHP symbols

- Actions are verbs: `ResolveFrontendResourcePlanAction`, `AssertPublicRenderPerformanceBudgetAction`, `BuildPublicRenderSafetyReportAction`.
- Support classes are capabilities or roles: `PublicViewQueryGuard`, `AdminSurfaceLookup`, `ThemePackageCandidates`.
- Enums name a singular concept. Do not add an `Enum` suffix to a new enum unless it extends an already suffix-based public family. Never rename an existing enum solely to change the suffix.
- Events describe something that happened and use past tense, such as `PagePublished` rather than `PublishPage`.
- Boolean properties and methods read as predicates: `isPaid`, `hasTable()`, `canInstall()`, `shouldLogVisit()`.
- Interfaces name the capability. Use `Interface` only where the established family already uses it; do not churn existing contract names.

Three legacy Action classes intentionally do not have the suffix because their class names are already consumed: `AssignPermissionsToRole`, `GetMaxUploadSizeInBytes`, and `BladeComponentFacadeResolver`. New exceptions require an explicit compatibility rationale.

### Persisted and framework names

| Surface                     | Canonical form                                                                            | Example                                           |
| --------------------------- | ----------------------------------------------------------------------------------------- | ------------------------------------------------- |
| Database tables and columns | `snake_case`, plural tables, `_id` foreign keys                                           | `public_render_contract_events`, `page_id`        |
| Config namespace            | package name in kebab case                                                                | `capell-frontend`                                 |
| Nested config keys          | dot-delimited path with `snake_case` segments                                             | `capell-frontend.public_view_query_guard.enabled` |
| Translation namespace/group | package namespace plus dot-delimited group                                                | `capell-admin::reports.*`                         |
| Translation leaf key        | `snake_case`                                                                              | `public_render_safety_metric_failures`            |
| Route name                  | dot-delimited hierarchy; keep established package prefix and kebab-case compound segments | `capell-marketplace.install-flow.callback`        |
| Blade component/view path   | directories and filenames in kebab case                                                   | `components/page/neighbor-link.blade.php`         |

These names are observable contracts. Existing mixed route prefixes and the legacy `site_domain_item_label` component are not renamed in a standards-only change.

## Laravel idiom

### Validation and authorization

Use a Form Request at a conventional HTTP controller boundary when request validation and authorization form one reusable contract. Filament and Livewire operations use their component/schema validation APIs, then pass typed or documented arrays into Actions. Domain invariants remain in the Action or domain object so console, queue, and programmatic callers cannot bypass them.

`ValidatePageAuthoringAction` is the extension-aware authoring boundary: UI layers collect form state, while tagged validators enforce package rules independently of the delivery mechanism.

Authorization occurs at the delivery boundary and again inside reusable mutating operations when they can be called independently. Never rely on a hidden button as authorization.

### Queries

Use a model scope for a reusable domain predicate or ordering, especially when it appears in multiple queries. Keep a one-off, local filter inline when naming it would not add domain meaning. Eager-load relationships used in loops or view-data assembly.

The scopes in `packages/core/src/Models/Concerns/HasPublishDates.php` are the reference for named visibility predicates. `PagesTable` composes scopes and typed query callbacks rather than duplicating visibility SQL.

No database query, relationship lazy load, authentication lookup, or service resolution belongs in a public Blade view. Prepare render data before calling the view.

### Config, environment, and facades

Read environment variables only from config files. Runtime code reads `config()` or a typed settings/config collaborator. This is enforced by Pest architecture tests.

Prefer injected collaborators in domain Actions and Support classes when an interface or established service exists. Facades are acceptable at Laravel integration boundaries—service providers, console commands, Eloquent transactions, framework macros—and in narrowly scoped adapters. Do not replace a clear injected dependency with a facade for convenience.

### Dates

Use `CarbonImmutable` for Data objects, policy decisions, timestamps passed between layers, and new immutable Eloquent casts. Respect an existing framework signature or mutable model contract rather than converting it opportunistically.

`packages/marketplace/src/Data/MarketplaceInstallPolicyEvidenceData.php` and `MarketplaceInstallIntent` are the reference immutable Data/cast shapes.

## Blade and Tailwind

Public output is a security and performance boundary. Read `docs/frontend/public-html-safety.md` and `docs/frontend/tailwind-vendor-css.md` before changing it.

### Tailwind first

Use Tailwind utilities in Blade for layout, spacing, typography, colour, borders, responsive states, focus states, and other utility-shaped presentation. Let the configured Prettier/Tailwind tooling order classes; do not alphabetize them by hand.

Project-authored CSS is limited to these established homes:

- `packages/admin/resources/css/capell-admin.css` for Filament/vendor selectors and admin-wide integration;
- `packages/frontend/resources/css/base/*.css` for public base tokens, typography, links, interactions, and the default theme;
- `packages/frontend/resources/css/capell-frontend.css` as the frontend source entry point;
- `packages/installer/resources/css/installer.css` for the standalone pre-install UI;
- `packages/frontend/publishes/build/capell-frontend.css` as a generated/published artifact, never a hand-edited source.

Custom CSS is justified for vendor DOM that Capell cannot decorate, pseudo-elements, keyframes, complex selectors, CSS variables/tokens, or generated public assets. A new utility-shaped selector is not an exception. Critical CSS is produced by the frontend resource/optimizer pipeline; do not add hand-authored Blade critical-CSS partials.

### Components and props

Anonymous component paths use kebab-case directories and filenames. Declare public inputs with `@props` and defaults when the view is an anonymous component. Filament-owned views and template views that receive named data from the renderer do not add fake `@props` declarations.

Narrow union or scalar inputs once near the top of the view, or preferably before rendering. Do not place fully qualified class names or namespace separators in Blade `@php` blocks; import or prepare the value in PHP code instead. Keep `@php` blocks small and presentation-only.

### Public rendering and accessibility

- Public/marketing pages render static server HTML. Do not mount `<livewire:...>` or `@livewire(...)` from public Blade.
- Interactive public widgets must use the documented frontend interaction/runtime boundary and must not expose authoring metadata.
- Every form control has an associated label or an explicit accessible name.
- Images have meaningful `alt` text; decorative images use `alt=""`.
- Icon-only controls have an accessible label.
- Interactive elements retain a visible keyboard focus state.
- Use semantic elements before adding ARIA. Dynamic status messages use the appropriate live-region semantics.

`packages/frontend/tests/Arch/PublicBladeSafetyTest.php` mechanically protects the public-view boundary.

## Livewire

- Type every public property. Use nullable types for genuinely absent state rather than sentinel strings.
- Treat public properties as untrusted request input on every call; validate or narrow before use.
- Authorize each mutating public method at the point of mutation. Do not rely on `mount()` authorization because subsequent requests are independent.
- Public methods that perform work use verb names. Derived, read-only values use computed-property nouns.
- Keep business operations in Actions; the component coordinates input, authorization, UI state, and notifications.
- Public marketing output is static SSR unless a documented product requirement explicitly needs the frontend interaction runtime. Admin Livewire use is not affected by that rule.

`packages/admin/src/Livewire/PageApprovalStatus.php` and the header components are representative typed admin components. Public page Blade remains query-free and Livewire-tag-free even though the frontend package supports controlled renderable widgets elsewhere.

## Pest tests

### Shape and naming

Use `it('describes observable behaviour', function (): void { ... });`. Descriptions are lower-case prose and complete the phrase “it …”. Do not use snake-case descriptions in new tests. Use `describe()` only when it removes repeated context or clarifies a coherent state machine.

Each test owns one behaviour or contract. It may use several assertions to prove that behaviour; “one behaviour” does not mean “one assertion”. A regression test names the externally visible guarantee, not the private method that happened to fail.

### Data and isolation

- Use datasets for the same behaviour across meaningful inputs, not to hide unrelated scenarios in one callback.
- Use factories and named factory states for domain records. Inline attributes are appropriate only for the values material to that scenario.
- Freeze time when an assertion depends on the clock and restore it through the test lifecycle.
- Fake queues, events, HTTP, storage, and external services at the narrowest boundary.
- Tests must pass independently and in any order. Root `tests/Feature` and `tests/Integration` may cover cross-package contracts; package-owned behaviour stays with its package.

### Expectations and PHPStan

Prefer a readable expectation chain when every link retains the same concrete subject type. Split the chain into separately typed expectations when Pest's higher-order generic proxy loses the subject type or when the failure message becomes ambiguous. Do not add a PHPStan baseline entry to preserve a clever chain.

`packages/frontend/tests/Unit/Actions/BuildPublicRenderPerformanceReportActionTest.php` is a compact Action test. The public Blade architecture tests are reference examples for repository contract scans.

Never change an assertion merely to accept output changed by a standards pass. Revert the production change or classify it as a separate behavioural change.

## Errors, logging, and user messages

- Throw a domain/package exception when the caller can recover or when the failure is part of the package contract.
- Use `InvalidArgumentException` for programmer input that violates a local API; use a named exception when callers need to distinguish the failure.
- At HTTP, console, queue, and Filament boundaries, translate a domain failure into the framework response without leaking secrets or internal exception text.
- Catch only when adding context, translating exception type, performing required cleanup, or executing an explicitly safe fallback.
- Never use an empty catch. If a failure is deliberately tolerated, state the precise constraint and log unexpected variants at the layer that owns operational reporting.
- Do not log and rethrow at every layer. The layer with request/job/command context owns the operational log.
- Logs state the failed operation and include structured, non-secret context. User-facing messages are translated, sentence case, calm, and actionable.

`CloudBootstrapCommand::forceAdminPasswordChange()` is the reference for a best-effort optional integration that explains the tolerated condition and logs enough context to diagnose unexpected failure.

## Comments and public documentation

Comments explain constraints the code cannot make obvious: compatibility, framework limitations, security boundaries, non-obvious performance ownership, or why a safer-looking alternative is invalid. Do not narrate syntax, preserve deleted code, record change history, or leave a bare `TODO` without ownership and a decision condition.

Every public extension point package authors are expected to implement or call has a documentation-quality docblock. It should answer:

- what capability the contract provides;
- when Capell invokes it;
- what inputs and outputs mean, including array shapes/generics;
- whether exceptions, side effects, ordering, or idempotency matter;
- how the implementation is registered or discovered.

Internal methods need docblocks only when they carry generic/shape information or a non-obvious contract. Prefer expressive names and types over ceremonial prose.

## Migrations

Use an anonymous migration class with typed `up(): void` and `down(): void`. Tables and columns use snake case, foreign keys end in `_id`, and indexes/constraints are named only when Laravel's generated name is unstable or too long.

Package migrations that may run during partial installation, upgrade, or uninstall guard shared/custom tables and columns with the established `Schema::hasTable()`/`hasColumn()` checks. A rollback must reverse only what that migration owns; never drop user data opportunistically.

## Verification and exceptions

Run all project tooling through the Docker harness:

```bash
./capell pint --dirty --format agent
./capell test --compact --filter=RelevantBehaviour
./capell composer analyze
```

Use the narrowest command that proves the edit while iterating. Run `./capell composer preflight` once at the final integration boundary when the task calls for it.

When a rule cannot be followed, document the compatibility or framework reason next to the exception and add the narrowest mechanical allow-list entry possible. An exception must identify an existing contract; “the tool complained” and “this was quicker” are not rationales.
