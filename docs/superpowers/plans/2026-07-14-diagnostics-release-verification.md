# Diagnostics and Release Verification Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make installation diagnostics structurally trustworthy and make every documented verification command non-mutating.

**Architecture:** Core doctor checks use stable IDs, native severity, structured evidence, and one required-schema catalog. Installation state is resolved from schema footprint plus the existing `capell_extensions` Core lifecycle row. Admin renders the same findings as an Operations Center. Composer exposes separate check and fix command families protected by a script-contract test.

**Tech Stack:** PHP 8.4+, Laravel Schema/Eloquent/Auth, Filament 4, Spatie Laravel Data, Composer scripts, Pest.

---

### Task 1: Add stable diagnostic identity and severity

**Files:**
- Create: `packages/core/src/Enums/Diagnostics/DoctorCheckSeverity.php`
- Modify: `packages/core/src/Data/Diagnostics/DoctorCheckResultData.php`
- Modify: `packages/core/src/Data/Diagnostics/DoctorReportData.php`
- Modify: `packages/core/src/Console/Commands/DoctorCommand.php`
- Modify: `packages/core/src/Console/Commands/ThemeDoctorCommand.php`
- Test: `packages/core/tests/Unit/Data/Diagnostics/DoctorCheckResultDataTest.php`

- [ ] **Step 1: Write failing serialization tests** proving a harmless label change does not change ID, severity, pass/fail behavior, JSON keys, or report status.

- [ ] **Step 2: Define severity** as `info`, `warning`, and `critical` and change the DTO constructor to require `id`, `severity`, `label`, `passed`, `message`, optional `remediation`, and `evidence`.

- [ ] **Step 3: Preserve structured output** with this shape:

  ```php
  /** @return array{id: string, severity: string, label: string, passed: bool, message: string, remediation: string|null, evidence: array<string, mixed>} */
  public function toArray(): array
  ```

- [ ] **Step 4: Update command rendering** to select icons/exit behavior from native severity and `passed`, never label text.

- [ ] **Step 5: Run and commit**

  Run: `vendor/bin/pest packages/core/tests/Unit/Data/Diagnostics/DoctorCheckResultDataTest.php packages/core/tests/Feature/Console/DoctorCommandTest.php`

  ```bash
  git add packages/core/src packages/core/tests
  git commit -m "feat(core): stabilize diagnostic findings"
  ```

### Task 2: Establish the authoritative runtime schema contract

**Files:**
- Create: `packages/core/src/Support/Diagnostics/CapellRuntimeSchemaContract.php`
- Create: `packages/core/src/Enums/Diagnostics/CapellInstallationState.php`
- Create: `packages/core/src/Actions/Diagnostics/ResolveCapellInstallationStateAction.php`
- Modify: `packages/core/src/Actions/Diagnostics/BuildDoctorReportAction.php`
- Test: `packages/core/tests/Feature/Diagnostics/CapellInstallationStateTest.php`

- [ ] **Step 1: Write the schema matrix first** for no footprint, only `sites`, only `capell_extensions`, Core row absent, Core row not installed, missing one required Core table, missing theme/layout table, missing `stored_events`, missing `snapshots`, missing `page_workflow_states`, and complete installed state.

- [ ] **Step 2: Build one catalog** with explicit groups: footprint anchors, required Core tables, theme/layout tables, and event-sourcing tables. Source table names from Core migrations/`HasMigrations`; do not duplicate the list in doctor/report Actions.

- [ ] **Step 3: Implement state resolution** exactly:

  - `not_installed`: no catalog footprint and no lifecycle table/record.
  - `partial`: any footprint exists while Core is unrecorded/not installed, or any required table is missing.
  - `installed`: `capell_extensions` records `capell-app/core` installed and every required table exists.

- [ ] **Step 4: Make schema findings critical** for every partial state and include missing table names in evidence.

- [ ] **Step 5: Run and commit**

  Run: `vendor/bin/pest packages/core/tests/Feature/Diagnostics/CapellInstallationStateTest.php`

  ```bash
  git add packages/core/src/Support/Diagnostics packages/core/src/Enums/Diagnostics packages/core/src/Actions/Diagnostics packages/core/tests/Feature/Diagnostics
  git commit -m "fix(core): resolve installation state from one schema contract"
  ```

### Task 3: Prove real Admin access

**Files:**
- Create: `packages/core/src/Actions/Diagnostics/CheckAdminPanelAccessAction.php`
- Modify: `packages/core/src/Actions/Diagnostics/BuildDoctorReportAction.php`
- Test: `packages/core/tests/Feature/Diagnostics/AdminPanelAccessCheckTest.php`

- [ ] **Step 1: Add failing cases** for no users, valid admin, orphan role assignment, wrong configured user model, wrong guard, role assigned to another morph type, role name present without permission, and a user whose `canAccessPanel()` returns false.

- [ ] **Step 2: Resolve actual configuration** for auth provider model, permission role model, guard, and Filament panel. Query concrete users, verify role pivot morph type/guard, then call real panel access on candidate users.

- [ ] **Step 3: Return critical evidence** containing counts and configured class/guard names, with personal fields removed.

- [ ] **Step 4: Run and commit**

  Run: `vendor/bin/pest packages/core/tests/Feature/Diagnostics/AdminPanelAccessCheckTest.php`

  ```bash
  git add packages/core/src/Actions/Diagnostics packages/core/tests/Feature/Diagnostics
  git commit -m "fix(core): verify effective admin panel access"
  ```

### Task 4: Rebuild Demo/Install Health as an Operations Center snapshot

**Files:**
- Modify: `packages/admin/src/Actions/Reports/BuildDemoInstallHealthReportAction.php`
- Modify: `packages/admin/src/Data/Reports/ReportFindingData.php`
- Modify: `packages/admin/src/Data/Reports/ReportSnapshotData.php`
- Modify: `packages/admin/src/Filament/Pages/Reports/DemoInstallHealthReport.php`
- Modify: `packages/admin/resources/lang/en/reports.php`
- Modify: `packages/admin/tests/Feature/Reports/DemoInstallHealthReportTest.php`
- Create: `packages/admin/tests/Feature/Reports/OperationsCenterRerunTest.php`

- [ ] **Step 1: Replace broad happy-path tests** with the installation-state matrix. Assert only `not_installed` produces a genuine empty snapshot; every partial case produces critical findings; installed state reports individual operational findings.

- [ ] **Step 2: Map doctor results directly** by stable ID/severity/evidence. Delete label-string severity inference such as `doctorCheckSeverity()`.

- [ ] **Step 3: Add generated time and rerun**. `ReportSnapshotData` carries `generatedAt`; the Filament page exposes a translated re-run action that rebuilds rather than reusing the prior Livewire property/cache.

- [ ] **Step 4: Render evidence/remediation** for schema, queue, cache, storage, package compatibility, admin access, and public-route health without embedding diagnostic logic in the page.

- [ ] **Step 5: Run and commit**

  Run: `vendor/bin/pest packages/admin/tests/Feature/Reports/DemoInstallHealthReportTest.php packages/admin/tests/Feature/Reports/OperationsCenterRerunTest.php packages/admin/tests/Feature/Filament/Pages/ReportPagesAccessTest.php`

  ```bash
  git add packages/admin/src packages/admin/resources/lang/en/reports.php packages/admin/tests
  git commit -m "feat(admin): turn install health into operations center"
  ```

### Task 5: Migrate all doctor producers to stable checks

**Files:**
- Modify: `packages/core/src/Actions/Diagnostics/BuildDoctorReportAction.php`
- Modify: `packages/core/src/Actions/Diagnostics/BuildThemeDoctorReportAction.php`
- Modify: package doctor producers returned by `CapellCore::getPackages()`
- Create: `packages/core/tests/Arch/DoctorCheckContractTest.php`

- [ ] **Step 1: Add a contract test** that instantiates every Core/theme/package doctor result, asserts a unique kebab/dot stable ID, native severity, non-empty remediation for failed critical checks, and JSON round-trip preservation.

- [ ] **Step 2: Assign namespaced IDs** such as `core.schema.required`, `core.admin.access`, and `theme.manifest.valid`. Package doctor JSON lacking IDs/severity is invalid and yields a critical `package-doctor.invalid-contract` finding.

- [ ] **Step 3: Run all doctor tests**

  Run: `vendor/bin/pest packages/core/tests --filter=Doctor packages/admin/tests --filter=Health`

  Expected: PASS with no constructor call using the old four-argument shape.

- [ ] **Step 4: Commit**

  ```bash
  git add packages/core packages/admin
  git commit -m "refactor(diagnostics): migrate doctor checks to stable contract"
  ```

### Task 6: Separate check and fix Composer commands

**Files:**
- Modify: `composer.json`
- Modify: `docs/development/ci.md`
- Modify: `docs/development/commands.md`
- Create: `tests/Unit/NonMutatingComposerScriptsTest.php`

- [ ] **Step 1: Write a failing script-level test** that recursively expands Composer script aliases documented as checks and rejects `rector` without `--dry-run`, `pint` without `--test`, formatter writes, or known transformation commands.

- [ ] **Step 2: Change scripts** so `preflight:all` uses `@rector:all:check` and `@cs:check`. Add `preflight:fix` containing the intentional `@rector:all` and `@cs:fix` commands followed by checks.

- [ ] **Step 3: Update docs** to state which commands mutate and which are CI-safe.

- [ ] **Step 4: Run the contract test**

  Run: `vendor/bin/pest tests/Unit/NonMutatingComposerScriptsTest.php`

  Expected: PASS.

- [ ] **Step 5: Prove clean-tree behavior**

  ```bash
  before="$(git status --short)"
  composer preflight:all
  test "$before" = "$(git status --short)"
  ```

  Expected: `composer preflight:all` exits 0 and the status strings are identical.

- [ ] **Step 6: Commit**

  ```bash
  git add composer.json docs/development tests/Unit/NonMutatingComposerScriptsTest.php
  git commit -m "fix(quality): make preflight checks non-mutating"
  ```

## Exit gate

- No-footprint installs alone render the empty state.
- Every asymmetric/partial schema case is critical, including missing workflow, snapshot, theme, layout, or event tables.
- Effective Admin access is proven, not inferred from row counts.
- Labels cannot affect severity or behavior.
- Operations Center findings carry stable ID, evidence, remediation, and generated time and can be rerun.
- `composer preflight:all` leaves tracked and untracked status unchanged.
