# README And Documentation Usability Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the Capell repository documentation easier for evaluators, installers, extension developers, and maintainers to scan, trust, and navigate.

**Architecture:** Keep detailed guidance in the existing leaf documents and improve the entry points that route readers to it. The root README will introduce and prove the product, the docs home and section indexes will route by reader task, and each package README will remain useful when published from its split repository.

**Tech Stack:** Markdown, Composer metadata and scripts, GitHub Actions YAML, Codecov YAML, PHP documentation validation scripts, Prettier.

---

## File Structure

- `README.md`: public product, install, architecture, repository, contribution, and support entry point.
- `docs/README.md`: task-led route map for the complete documentation set.
- `docs/getting-started/index.md`: evaluator, installer, and first-use route map.
- `docs/admin/index.md`: editor and admin-integrator route map.
- `docs/frontend/index.md`: public rendering and frontend extension route map.
- `docs/packages/README.md`: extension-author route map.
- `docs/operations/index.md`: operator route map and runbook index.
- `docs/development/index.md`: host-repository maintainer route map.
- `docs/performance/README.md`: performance-topic route map.
- `docs/reference/index.md`: compact lookup index.
- `packages/core/README.md`: standalone Core package boundary and operating guide.
- `packages/admin/README.md`: standalone Admin package boundary and operating guide.
- `packages/frontend/README.md`: standalone Frontend package boundary and operating guide.
- `packages/installer/README.md`: standalone Installer package boundary and operating guide.
- `packages/marketplace/README.md`: standalone Marketplace package boundary and operating guide.
- `.github/workflows/coverage-release.yml`: release coverage generation and Codecov publication.
- `codecov.yml`: project and package-component coverage policy.

No new validation scripts or documentation pages are planned. Existing leaf documents change only when an audit identifies a broken link, malformed Markdown, or source-backed factual problem.

### Task 1: Establish The Documentation Baseline

**Files:**
- Inspect: `README.md`
- Inspect: `docs/**/*.md`
- Inspect: `packages/*/README.md`
- Inspect: `packages/*/docs/**/*.md`
- Inspect: `composer.json`
- Inspect: `packages/*/composer.json`
- Inspect: `.github/workflows/coverage-release.yml`
- Inspect: `codecov.yml`

- [ ] **Step 1: Run the repository documentation guards**

Run:

```bash
php scripts/check-docs-links.php
php scripts/check-docs-env-vars.php
php scripts/check-root-docs.php
```

Expected: each command exits `0`; record every reported issue before editing.

- [ ] **Step 2: Check the changed files for whitespace and formatting defects**

Run:

```bash
git diff --check
npx prettier --check README.md docs/README.md docs/*/index.md docs/packages/README.md docs/performance/README.md packages/*/README.md .github/workflows/coverage-release.yml codecov.yml
```

Expected: `git diff --check` exits `0`; Prettier may identify files requiring formatting, which become part of Tasks 2–5.

- [ ] **Step 3: Verify product claims against repository sources**

Check the version constraints, package names, scripts, providers, configuration keys, install commands, and extension symbols cited by the entry-point docs against `composer.json`, package Composer files, config files, providers, commands, and current tests. Record corrections directly in the relevant task below rather than creating a separate audit document.

### Task 2: Tighten The Root Reader Journey

**Files:**
- Modify: `README.md`

- [ ] **Step 1: Keep the opening decision-oriented**

Retain the banner, status badges, concise Laravel/Filament positioning, and admin proof. Make the first screen answer: what Capell is, who controls the frontend, and where a new reader starts.

- [ ] **Step 2: Consolidate duplicated routes**

Keep one compact task-led start table covering trial, existing-app install, concepts, extension development, editor onboarding, frontend work, and repository contribution. Remove repeated links or explanations that do not add a new decision.

- [ ] **Step 3: Preserve source-backed technical detail**

Keep the package boundary table, supported versions, verified installer and manual Composer commands, extension-point summary, contribution commands, licensing, support, and public-output safety contract. Tighten paragraphs and table copy without changing their meaning.

- [ ] **Step 4: Format the root README**

Run:

```bash
npx prettier --write README.md
```

Expected: Prettier exits `0` and only normalizes Markdown formatting.

### Task 3: Rebuild Navigation Around Reader Jobs

**Files:**
- Modify: `docs/README.md`
- Modify: `docs/getting-started/index.md`
- Modify: `docs/admin/index.md`
- Modify: `docs/frontend/index.md`
- Modify: `docs/packages/README.md`
- Modify: `docs/operations/index.md`
- Modify: `docs/development/index.md`
- Modify: `docs/performance/README.md`
- Modify: `docs/reference/index.md`

- [ ] **Step 1: Replace the overloaded docs-home list with reader paths**

In `docs/README.md`, route readers through five jobs: evaluate/install Capell, build and edit a site, build an extension, operate a production installation, and maintain the host repository. Keep the visual tour, major section index, common high-risk decisions, host-package map, and documentation ownership rules only where each adds a distinct route.

- [ ] **Step 2: Make every section index task-led**

For each section index, keep a short audience statement followed by a table whose first column describes the reader's task and whose second column points to the owning guide. Move implementation detail into existing leaf pages and remove repeated narrative that belongs in those pages.

- [ ] **Step 3: Preserve safety and ownership boundaries**

Keep the anonymous public-output safety rule visible in Frontend and Packages. Keep host-versus-add-on ownership visible in Development and Packages. Keep backup, lockdown, upgrade, and rollback boundaries visible in Operations.

- [ ] **Step 4: Fix concrete Markdown defects discovered by the audit**

Correct malformed table rows, inconsistent heading capitalization where it harms navigation, orphaned links, and repeated screenshots that do not help the reader choose a path.

- [ ] **Step 5: Format the documentation indexes**

Run:

```bash
npx prettier --write docs/README.md docs/*/index.md docs/packages/README.md docs/performance/README.md
```

Expected: Prettier exits `0` and the edited tables render consistently.

### Task 4: Make Package READMEs Stand Alone

**Files:**
- Modify: `packages/core/README.md`
- Modify: `packages/admin/README.md`
- Modify: `packages/frontend/README.md`
- Modify: `packages/installer/README.md`
- Modify: `packages/marketplace/README.md`

- [ ] **Step 1: Standardize the package opening**

For each package, keep the package name, monorepo release/test/quality/coverage badges, one-paragraph responsibility, and an explicit package boundary. Do not copy the root product pitch into every package.

- [ ] **Step 2: Keep package-specific operating detail**

Retain only the install command, real environment variables, runtime surfaces, main flows, safety constraints, focused verification commands, concrete troubleshooting, and deeper links owned by that package.

- [ ] **Step 3: Check split-repository behavior**

Ensure each README explains that development happens in the host monorepo, points users to the published Capell docs for cross-package guidance, and does not rely on monorepo-relative links for essential package usage.

- [ ] **Step 4: Format package READMEs**

Run:

```bash
npx prettier --write packages/core/README.md packages/admin/README.md packages/frontend/README.md packages/installer/README.md packages/marketplace/README.md
```

Expected: Prettier exits `0` and all package tables and badges remain valid Markdown.

### Task 5: Validate Coverage Metadata And Badges

**Files:**
- Modify: `.github/workflows/coverage-release.yml`
- Modify: `codecov.yml`
- Verify: `README.md`
- Verify: `packages/core/README.md`
- Verify: `packages/admin/README.md`
- Verify: `packages/frontend/README.md`
- Verify: `packages/installer/README.md`
- Verify: `packages/marketplace/README.md`

- [ ] **Step 1: Review the coverage workflow contract**

Confirm the workflow generates `coverage/clover.xml`, uploads the same path as an artifact, publishes that exact file through the pinned Codecov action using OIDC, and has the required `id-token: write` permission.

- [ ] **Step 2: Review Codecov component paths**

Confirm `codecov.yml` sets project and patch status explicitly and maps Core, Admin, Frontend, Installer, and Marketplace components to their exact `packages/<name>/**` paths.

- [ ] **Step 3: Verify badge targets**

Confirm the root and package README badges point to the public monorepo release, current workflow filenames on `main`, the Codecov repository, PHP 8.4, the supported Laravel range, and published documentation.

- [ ] **Step 4: Validate YAML formatting**

Run:

```bash
npx prettier --write .github/workflows/coverage-release.yml codecov.yml
pre-commit run check-yaml --files .github/workflows/coverage-release.yml codecov.yml
```

Expected: both commands exit `0`.

### Task 6: Verify And Commit The Documentation Pass

**Files:**
- Verify: all files changed by Tasks 2–5

- [ ] **Step 1: Re-run documentation guards**

Run:

```bash
php scripts/check-docs-links.php
php scripts/check-docs-env-vars.php
php scripts/check-root-docs.php
```

Expected: all three commands exit `0` with no broken local links, undocumented environment variables, or unexpected root Markdown files.

- [ ] **Step 2: Run final formatting and metadata checks**

Run:

```bash
npx prettier --check README.md docs/README.md docs/*/index.md docs/packages/README.md docs/performance/README.md packages/*/README.md .github/workflows/coverage-release.yml codecov.yml
composer run check:composer-paths
composer run check:composer-lock
git diff --check
```

Expected: every command exits `0`.

- [ ] **Step 3: Review the complete implementation diff**

Run:

```bash
git diff -- README.md docs .github/workflows/coverage-release.yml codecov.yml packages/*/README.md
git status --short
```

Expected: the diff contains only the approved documentation, badge, coverage workflow, Codecov, design, and plan work; no product code, generated files, secrets, or environment churn appear.

- [ ] **Step 4: Commit the implementation**

Run:

```bash
git add README.md docs/README.md docs/*/index.md docs/packages/README.md docs/performance/README.md packages/core/README.md packages/admin/README.md packages/frontend/README.md packages/installer/README.md packages/marketplace/README.md .github/workflows/coverage-release.yml codecov.yml docs/superpowers/plans/2026-07-12-readme-docs-usability.md
git commit -m "docs: improve readme and navigation"
```

Expected: pre-commit hooks pass and Git creates one focused implementation commit.
