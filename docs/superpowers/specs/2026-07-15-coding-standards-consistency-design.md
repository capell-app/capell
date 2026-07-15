# Coding standards and consistency overhaul design

## Goal

Make the Capell host monorepo read as one deliberately engineered Laravel product without changing runtime behaviour or public compatibility.

The work has four outputs:

1. an evidence-based coding standard contributors can follow;
2. mechanical guards for high-confidence repository invariants;
3. focused, reversible consistency sweeps;
4. a verified debt ledger for changes that require behavioural or compatibility migrations.

## Constraints

- Runtime output, database effects, authorization, public names, and extension contracts must remain unchanged.
- All PHP tooling runs in the Docker harness.
- The full `composer preflight` command runs once, after all narrow verification.
- Existing user changes in the worktree are preserved and excluded from standards commits.
- PHPStan ignores may shrink but never grow.
- Mechanical enforcement is introduced only for a rule already at or brought to 100% compliance in the same theme.

## Inventory and decisions

The inventory sampled Actions, Support classes, enums, every Livewire component, Blade views, migrations, and Pest tests across Core, Admin, Frontend, Installer, Marketplace, and root integration tests. Repository-wide measurements then tested whether sample conclusions held globally.

### Rules safe to enforce

- Strict types: all 3,451 inspected PHP files already comply and Pint already carries `declare_strict_types`.
- Typed Livewire state: all nine Livewire classes comply.
- No runtime `env()` access: existing architecture tests enforce it and the scan found only fixture/config text outside config files.
- Public Blade query/authoring safety: an existing architecture test owns the boundary; it can safely add Livewire-tag and authored-critical-CSS patterns because current public views comply.
- Public frontend request-path cache safety: frontend production code has no `rememberForever` call and can be guarded without allow-list debt.
- Pest entry-point style: `it()` is overwhelmingly dominant; remaining top-level `test()` aliases can be replaced mechanically without changing callbacks or assertions, then guarded.
- PHPStan suppression rationale: the small inline set can be made explicit, then guarded without broadening analysis ignores.

### Defaults to document, not mass-apply

- `final`: about 64% of Actions are final. Open Filament/framework classes and downstream extension seams prevent a safe blind sweep.
- enum suffixes: 83 semantic enum names and 66 `*Enum` names form a split public API. Renames are compatibility work.
- Action suffix/`handle()`: three live legacy Action names lack the suffix, while abstract report helpers and a resolver intentionally differ from the standard Action shape. New code follows the rule; existing names remain stable.
- Blade `@props`: anonymous components use it where they own inputs, but Filament/template-owned views receive renderer data. A universal rule would be false.
- custom CSS: the source files contain vendor integration, tokens, keyframes, generated/public asset concerns, and utility-shaped declarations. Deletion or migration needs rendered visual verification and cannot be included in a behaviour-preserving standards sweep.

## Change architecture

### Documentation theme

Add `docs/standards/coding-standards.md`, link it from contributor/development navigation, and preserve the inventory rationale in this design. The standard explicitly distinguishes a new-code default from a mechanically enforced invariant.

### Harness theme

The prompt's canonical `./capell pint ...` command is not currently supported even though all other common tools have shortcuts. Add a transparent `pint` pass-through to `vendor/bin/pint` and a contract test for the help/mapping. This changes developer tooling only and makes the documented workflow executable.

### Architecture-guard theme

Extend the existing public Blade test rather than creating a parallel scanner. Add focused repository tests for:

- no Livewire mount directives in public frontend Blade;
- no authored critical-CSS includes/components in public frontend Blade;
- no forever cache calls in frontend production request code;
- no unexplained inline PHPStan suppression;
- no top-level `test()` definitions after the test-style sweep.

The scanners report file and line context, use explicit roots, and ignore generated/vendor paths.

### Static-analysis theme

Remove narrow path/count ignores by correcting truthful generic annotations, list normalization, callback return shapes, and model relation generics where runtime semantics are unchanged. Each group is accepted only when `./capell composer analyze` remains green and the baseline has fewer entries.

Broad package test ignores and framework inference limitations remain visible debt. This pass does not replace them with type lies or custom suppressions.

### Consistency themes

- Replace top-level Pest `test()` aliases with the canonical `it()` alias while preserving descriptions, callbacks, datasets, and assertions.
- Remove commented-out test bodies and changelog-style comments that execute nothing.
- Replace bare swallowed-catch markers with constraint comments; do not add logs where logging itself could be observable.
- Add documentation to high-value public extension contracts that lack an explanation, prioritizing registration and public-render seams.
- Use Pint only for touched PHP files and do not accept unrelated formatting churn.

## Compatibility ledger

The following are deliberately deferred:

- renaming `AssignPermissionsToRole`, `GetMaxUploadSizeInBytes`, or `BladeComponentFacadeResolver`;
- renaming the `site_domain_item_label` Blade component;
- normalizing existing route prefixes, config keys, translation keys, event names, or enum suffixes;
- sealing open classes without extension-use evidence;
- translating existing raw Blade strings where localized output would become newly observable;
- migrating or deleting custom CSS without browser parity evidence;
- changing cache duration/ownership or public-render resource behaviour;
- extracting duplicate runtime logic without dedicated behavioural coverage.

## Verification strategy

Each theme gets the smallest relevant Pest target, dirty-file Pint, PHPStan when type surfaces change, and `git diff --check`. The final repository state receives the one authorized `./capell composer preflight` run. Failures introduced by the sweep are fixed or the responsible change is reverted; assertions are never loosened to accept changed output.
