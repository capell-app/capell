# Coding standards and consistency implementation plan

> **Execution rule:** Keep every theme behaviour-preserving, verify narrowly, and commit it independently. Do not run the full preflight before the final task.

**Goal:** Codify and mechanically reinforce Capell's dominant coding conventions, reduce static-analysis debt, and make low-risk repository-wide consistency fixes.

**Architecture:** Existing Pint, PHPStan, and Pest architecture surfaces remain the enforcement owners. New checks extend those surfaces rather than adding dependencies or a second policy system.

**Tech stack:** PHP 8.4, Laravel 12/13, Filament 4, Livewire, Pest 4, PHPStan/Larastan, Laravel Pint, Bash/Docker Compose.

---

### Task 1: Establish and record the baseline

**Files:**

- Read: `CONTRIBUTING.md`
- Read: `docs/development/ci.md`
- Read: `docs/development/package-boundaries.md`
- Read: `docs/frontend/public-html-safety.md`
- Read: `pint.json`
- Read: `phpstan.neon`
- Read: `phpstan/common.neon`
- Read: `phpstan/ignore-errors.neon`

- [x] Create `chore/standards-consistency` from the clean starting revision.
- [x] Record that the requested `./capell pint` baseline command initially fails because the harness lacks a `pint` shortcut.
- [x] Run the equivalent `./capell exec vendor/bin/pint --dirty --format agent` and confirm it passes.
- [x] Run `./capell composer analyze` and confirm zero errors.
- [x] Sample 20–30 Actions, Support classes, enums, Blade views, migrations, and Pest tests, plus every Livewire component.
- [x] Quantify repository-wide compliance before selecting rules.

### Task 2: Publish the evidence-based standard

**Files:**

- Create: `docs/standards/coding-standards.md`
- Create: `docs/superpowers/specs/2026-07-15-coding-standards-consistency-design.md`
- Modify: `CONTRIBUTING.md`
- Modify: `docs/development/index.md`

- [x] Document PHP style, type discipline, naming, Laravel idiom, Blade/Tailwind, Livewire, Pest, error/logging, migration, and public-doc rules.
- [x] Give an in-repository example for every rule family.
- [x] Record rules that cannot be safely mass-enforced.
- [x] Link the standard from contributor and development navigation.
- [x] Run the documentation checks and `git diff --check`.
- [x] Commit as a documentation-only theme (`593a6dd8d`).

### Task 3: Make the Docker harness match the documented workflow

**Files:**

- Modify: `capell`
- Create: `tests/Unit/CapellHarnessTest.php`

- [ ] Add `pint [args]` to help output.
- [ ] Pass all Pint arguments to `vendor/bin/pint` in the running app container.
- [ ] Add a focused contract test for the shortcut and existing preflight mapping.
- [ ] Run `./capell test --compact tests/Unit/CapellHarnessTest.php`.
- [ ] Run `./capell pint --dirty --format agent`.
- [ ] Commit the harness theme.

### Task 4: Strengthen architecture guards

**Files:**

- Modify: `packages/frontend/tests/Arch/PublicBladeSafetyTest.php`
- Create: `packages/frontend/tests/Arch/FrontendRequestPathSafetyTest.php`
- Create: `packages/core/tests/Arch/InlinePhpstanSuppressionTest.php`

- [ ] Reject `<livewire:...>` and `@livewire(...)` in public frontend Blade.
- [ ] Reject authored critical-CSS includes/components in public frontend Blade.
- [ ] Reject forever-cache calls from frontend production PHP.
- [ ] Require identifier plus explanatory rationale on inline PHPStan ignores.
- [ ] Make current inline exceptions explicit without changing code execution.
- [ ] Run the three focused architecture test files and dirty-file Pint.
- [ ] Commit the architecture theme.

### Task 5: Shrink narrow PHPStan debt

**Files:**

- Modify: `phpstan/ignore-errors.neon`
- Modify: targeted Admin/Core/Marketplace files identified by path-scoped ignores

- [ ] Supply the `Pageable` generic and form-data array type across the authoring validator boundary.
- [ ] Make filter helper return lists without changing ordering or values.
- [ ] Truthfully narrow Cloud bootstrap response shapes.
- [ ] Replace inferred void callbacks that return expressions with block callbacks.
- [ ] Correct safe Eloquent relation generic annotations to `$this` where Larastan already infers `$this`.
- [ ] Remove every resolved path/count ignore in the same patch.
- [ ] Run `./capell composer analyze`; require fewer ignore entries and zero errors.
- [ ] Run affected Action/model tests and dirty-file Pint.
- [ ] Commit the PHPStan debt theme.

### Task 6: Normalize Pest entry points

**Files:**

- Modify: test files containing a top-level `test()` definition
- Create: `packages/core/tests/Arch/PestStyleTest.php`

- [ ] Replace only the top-level `test(` alias with `it(`; preserve descriptions, callbacks, datasets, and assertions.
- [ ] Add a repository guard that rejects future top-level `test()` definitions.
- [ ] Confirm the definition count changes from 370 `test()`/4,244 `it()` to zero `test()` and 4,614 `it()` before later additions.
- [ ] Run the affected package/root test shards and dirty-file Pint.
- [ ] Commit the Pest style theme.

### Task 7: Remove non-executing debris and clarify tolerated failures

**Files:**

- Modify: identified test files containing commented-out test bodies/setup
- Modify: four source files containing bare swallowed-catch markers

- [ ] Delete commented-out code that has no executable or reference value.
- [ ] Replace bare `//` catch bodies with a precise compatibility/framework constraint.
- [ ] Do not add logging or change exception propagation in this theme.
- [ ] Run the nearest focused tests, dirty-file Pint, and `git diff --check`.
- [ ] Commit the cleanup theme.

### Task 8: Document public extension contracts

**Files:**

- Modify: selected contracts under `packages/*/src/Contracts`

- [ ] Prioritize extension registration, public rendering, and authoring contracts with no interface-level documentation.
- [ ] Document invocation, registration, inputs/outputs, ordering, and side effects without altering signatures.
- [ ] Run dirty-file Pint and PHPStan.
- [ ] Commit the contract documentation theme.

### Task 9: Final review, one preflight, handoff, and publication

**Files:**

- Create outside repository: `~/.claude/HANDOFF-capell-standards.md`
- Update: this plan's checkboxes

- [ ] Review the complete branch diff for behavioural changes, unrelated user edits, generated noise, and baseline growth.
- [ ] Run all remaining narrow checks before the integration command.
- [ ] Run `./capell composer preflight` exactly once and preserve its output.
- [ ] Fix or revert any introduced failure without weakening tests.
- [ ] Write the handoff with completed/deferred sweeps, file/line risk ledger, resume point, verification, and commit list.
- [ ] Commit the final documentation/handoff references without including the external handoff file.
- [ ] Push `chore/standards-consistency`.
