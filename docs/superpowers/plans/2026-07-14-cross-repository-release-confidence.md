# Cross-Repository Release Confidence Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Prove a release candidate by solving and exercising the pinned foundation, curated companion packages, and real consuming application from clean checkouts.

**Architecture:** The existing `capell-packages-4/scripts/release-confidence` runner remains the single orchestration engine, but its release manifest pins all three repositories and its golden consumer becomes the real `/Users/ben/Sites/capell-app` application. Foundation and companion suites remain independently runnable; the cross-repository job adds clean solve, install, contract, browser, and evidence gates.

**Tech Stack:** PHP 8.4/8.5 as supported, Laravel 12/13, Composer 2, Filament 4 supported line, Pest, Playwright, GitHub Actions, JSON manifests.

---

## Repository ownership

- Foundation release contracts: `/Users/ben/Sites/packages/capell/capell-4`
- Harness and curated package catalog: `/Users/ben/Sites/packages/capell/capell-packages-4`
- Golden consuming application: `/Users/ben/Sites/capell-app`

### Task 1: Define one pinned release-candidate manifest

**Files:**
- Create: `/Users/ben/Sites/packages/capell/capell-packages-4/docs/v4-release-manifest.schema.json`
- Create: `/Users/ben/Sites/packages/capell/capell-packages-4/docs/v4-release-manifest.json`
- Modify: `/Users/ben/Sites/packages/capell/capell-packages-4/scripts/release-confidence/ReleaseConfidenceConfiguration.php`
- Create: `/Users/ben/Sites/packages/capell/capell-packages-4/tests/Unit/ReleaseManifestContractTest.php`

- [ ] **Step 1: Write failing schema tests** for missing repository SHA, dirty symbolic ref, duplicate package, absent curated dependency, unsupported matrix value, and Filament constraint mismatch.

- [ ] **Step 2: Define the manifest** with schema version, foundation repository/SHA, companion repository/SHA, consuming app repository/SHA, curated Composer packages, PHP/Laravel/Filament matrix, required gate IDs, and generation time. SHAs must be 40-character commits; branches/tags are informational only.

- [ ] **Step 3: Load only the manifest** in `ReleaseConfidenceConfiguration`; remove separate implicit HEAD/package discovery for approval decisions. Runtime discovery may verify but never replace pins.

- [ ] **Step 4: Run and commit**

  Run from the companion repo: `vendor/bin/pest tests/Unit/ReleaseManifestContractTest.php`

  ```bash
  git add docs/v4-release-manifest.schema.json docs/v4-release-manifest.json scripts/release-confidence tests/Unit/ReleaseManifestContractTest.php
  git commit -m "feat(release): pin cross-repository candidate manifest"
  ```

### Task 2: Align Composer and Filament constraints before solving

**Files:**
- Modify: `/Users/ben/Sites/packages/capell/capell-4/composer.json`
- Modify: foundation `packages/*/composer.json` files identified by the manifest
- Modify: `/Users/ben/Sites/packages/capell/capell-packages-4/composer.json`
- Modify: curated companion `packages/*/composer.json` files identified by the manifest
- Modify: `/Users/ben/Sites/capell-app/composer.json`
- Create: `/Users/ben/Sites/packages/capell/capell-packages-4/tests/Arch/ReleaseConstraintAlignmentTest.php`

- [ ] **Step 1: Add a failing constraint test** that parses every manifest package and asserts overlapping PHP, Laravel, Filament, Livewire, and foundation package constraints for each matrix coordinate.

- [ ] **Step 2: Normalize constraints** to the one supported Filament line and explicit Laravel 12/13 ranges. Do not use root-only aliases to hide incompatible package constraints.

- [ ] **Step 3: Run Composer validation independently** in all three repositories:

  ```bash
  composer validate --strict
  composer audit --locked
  ```

  Expected: all commands exit 0, or audit findings are recorded as release-blocking evidence rather than ignored.

- [ ] **Step 4: Run and commit focused constraint changes in each owning repository** using commit message `fix(release): align supported dependency constraints`.

### Task 3: Create clean pinned workspaces and a fresh solve

**Files:**
- Modify: `/Users/ben/Sites/packages/capell/capell-packages-4/scripts/release-confidence/ReleaseConfidenceRunner.php`
- Create: `/Users/ben/Sites/packages/capell/capell-packages-4/scripts/release-confidence/PinnedWorkspace.php`
- Create: `/Users/ben/Sites/packages/capell/capell-packages-4/scripts/release-confidence/ComposerPathRepository.php`
- Modify: `/Users/ben/Sites/packages/capell/capell-packages-4/tests/Unit/ReleaseConfidenceRunnerTest.php`

- [ ] **Step 1: Write failing runner tests** with temporary local Git fixtures. Assert detached checkout at each pinned SHA, clean status, generated path repositories with `symlink: false`, no inherited vendor/lock file, and failure on missing commit.

- [ ] **Step 2: Implement workspace creation** under a unique temporary root. Clone or `git worktree add --detach` each manifest repo, verify `git rev-parse HEAD`, and copy no mutable state from the developer checkout.

- [ ] **Step 3: Generate Composer repositories** for every pinned foundation/companion package and run a new solve in a clean copy of the pinned consuming app:

  ```bash
  composer update --no-interaction --no-progress --prefer-dist
  composer validate --strict
  composer audit --locked
  ```

- [ ] **Step 4: Record evidence** for command, exit code, duration, manifest SHA, lock hash, and installed package versions with secret redaction.

- [ ] **Step 5: Run and commit**

  Run: `vendor/bin/pest tests/Unit/ReleaseConfidenceRunnerTest.php`

  ```bash
  git add scripts/release-confidence tests/Unit/ReleaseConfidenceRunnerTest.php
  git commit -m "feat(release): solve pinned clean workspaces"
  ```

### Task 4: Run the supported Laravel/PHP matrix

**Files:**
- Modify: `/Users/ben/Sites/packages/capell/capell-packages-4/.github/workflows/coverage-release.yml`
- Create: `/Users/ben/Sites/packages/capell/capell-packages-4/.github/workflows/release-confidence.yml`
- Modify: `/Users/ben/Sites/packages/capell/capell-packages-4/scripts/release-confidence.php`
- Create: `/Users/ben/Sites/packages/capell/capell-packages-4/tests/Unit/ReleaseConfidenceWorkflowTest.php`

- [ ] **Step 1: Add workflow contract assertions** that every manifest PHP/Laravel coordinate appears in the matrix, uses the same manifest artifact, and cannot mark the gate successful when one coordinate is skipped/allowed to fail.

- [ ] **Step 2: Add matrix inputs** to the runner. Apply the Laravel constraint in the temporary consumer only, perform a fresh solve, and verify the resolved Filament version satisfies the manifest line.

- [ ] **Step 3: Upload per-coordinate evidence** including lock file, installed versions, logs, test results, and browser artifacts. Never write generated evidence back to a source checkout.

- [ ] **Step 4: Run the workflow test and commit**

  Run: `vendor/bin/pest tests/Unit/ReleaseConfidenceWorkflowTest.php`

  ```bash
  git add .github/workflows scripts/release-confidence.php tests/Unit/ReleaseConfidenceWorkflowTest.php
  git commit -m "ci(release): exercise supported laravel matrix"
  ```

### Task 5: Make the real consuming app the golden fixture

**Files:**
- Modify: `/Users/ben/Sites/packages/capell/capell-packages-4/scripts/release-confidence/ConsumerApplication.php`
- Retire or narrow: `/Users/ben/Sites/packages/capell/capell-packages-4/scripts/release-confidence/consumer/`
- Create: `/Users/ben/Sites/capell-app/tests/Feature/ReleaseConfidence/GoldenInstallTest.php`
- Create: `/Users/ben/Sites/capell-app/tests/Feature/ReleaseConfidence/ReleaseContractsTest.php`
- Create: `/Users/ben/Sites/capell-app/tests/Browser/release-confidence.mjs`

- [ ] **Step 1: Add red golden tests** for prompt-free fresh demo install, real Admin login/panel access, homepage resolution, fragment owner isolation/revocation, Marketplace beta policy, localized media cache invalidation, package install/upgrade, and damaged-install diagnostics.

- [ ] **Step 2: Prepare from the pinned app** using a temporary `.env`, isolated database/cache/storage, generated app key, and no production credentials. Run:

  ```bash
  php artisan migrate --force
  php artisan capell:install --no-interaction --url=http://127.0.0.1:8000 --all-packages --theme=none --name='Release Confidence' --email=release@example.test --password='release-confidence-password' --clear-cache --install-welcome-route
  ```

- [ ] **Step 3: Replace embedded-consumer overlap**. Keep only harness-specific extension fixtures that the real app does not own; document each retained fixture. The app itself is always checked out from its manifest SHA.

- [ ] **Step 4: Run feature and Playwright smoke** against the temporary app and retain screenshots/traces on failure.

- [ ] **Step 5: Commit app tests** with `test(release): add golden consuming-app contracts`, then commit harness integration with `refactor(release): use the pinned consuming application`.

### Task 6: Add permanent product contract gates

**Files:**
- Create: `/Users/ben/Sites/capell/capell-4/tests/Integration/Release/PublicFragmentReleaseContractTest.php`
- Create: `/Users/ben/Sites/capell/capell-4/tests/Integration/Release/PublishingPartitionReleaseContractTest.php`
- Create: `/Users/ben/Sites/capell/capell-4/tests/Integration/Release/MarketplacePolicyReleaseContractTest.php`
- Create: `/Users/ben/Sites/capell/capell-4/tests/Integration/Release/CacheMutationReleaseContractTest.php`
- Create: `/Users/ben/Sites/capell/capell-4/tests/Integration/Release/DiagnosticsDamageReleaseContractTest.php`
- Modify: `/Users/ben/Sites/packages/capell/capell-4/.github/workflows/public-release-smoke.yml`

- [ ] **Step 1: Build one test per named release invariant** from Plans 1–4; each must exercise real container bindings/persistence rather than restating a unit test.

- [ ] **Step 2: Add a release-contract Composer script** that runs only these deterministic tests and include it as a required harness stage before browser smoke.

- [ ] **Step 3: Add non-mutating quality stages**: Composer validation/audit, PHPStan, `composer preflight:all`, relevant Pest suites, and status-before/after assertion.

- [ ] **Step 4: Run and commit**

  Run: `vendor/bin/pest tests/Integration/Release`

  ```bash
  git add tests/Integration/Release .github/workflows/public-release-smoke.yml composer.json
  git commit -m "test(release): codify product release contracts"
  ```

### Task 7: Produce auditable release evidence and block approval

**Files:**
- Modify: `/Users/ben/Sites/packages/capell/capell-packages-4/scripts/release-confidence/ReleaseEvidenceRecorder.php`
- Modify: `/Users/ben/Sites/packages/capell/capell-packages-4/docs/v4-release-evidence.json`
- Modify: `/Users/ben/Sites/packages/capell/capell-packages-4/docs/v4-release-confidence.md`
- Create: `/Users/ben/Sites/packages/capell/capell-packages-4/tests/Unit/ReleaseEvidenceGateTest.php`

- [ ] **Step 1: Add failing evidence-gate tests** for an open P1/P2, absent matrix coordinate, missing required gate, failed clean-tree assertion, SHA mismatch, stale evidence timestamp, and unverified package.

- [ ] **Step 2: Record each gate** as stable ID, status, repository SHA, matrix coordinates, command/evidence artifact, and generated time. Calculate overall approval only from all required IDs passing and zero open P1/P2 findings.

- [ ] **Step 3: Run the full harness**

  Run from the companion repo: `php scripts/release-confidence.php run --manifest=docs/v4-release-manifest.json`

  Expected: exit 0 only when every first-release gate passes; otherwise exit non-zero with evidence retained.

- [ ] **Step 4: Commit evidence tooling**. Include generated release evidence only for the approved pinned manifest; exclude local-path and dirty-worktree runs.

## Exit gate

- The manifest pins exact foundation, companion, and app SHAs and the full curated set.
- Every supported Laravel 12/13 and PHP coordinate solves with the shared Filament line.
- Fresh demo install, Admin login, homepage, fragments, Marketplace, cache, package lifecycle, and diagnostics run in the real consuming app.
- Package suites remain independently runnable.
- Every named release contract and non-mutating quality command passes.
- Approval is impossible with any open P1/P2 or missing evidence.
