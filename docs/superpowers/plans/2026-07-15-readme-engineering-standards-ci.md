# README Engineering Standards CI Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make each README engineering-standard badge live or GitHub-Actions-enforced.

**Architecture:** A dependency-free PHP checker validates the README, PHPStan configuration, and workflows. The existing quality workflow runs it before dependencies install; coverage enforces the advertised 90% floor.

**Tech Stack:** PHP 8.4, Pest, Composer, GitHub Actions YAML, Shields.io, Codecov.

---

### Task 1: Add the engineering-standard contract

**Files:**

- Create: `scripts/check-readme-engineering-standards.php`
- Create: `tests/Unit/ReadmeEngineeringStandardsScriptTest.php`
- Modify: `composer.json`
- Modify: `.pre-commit-config.yaml`

- [x] **Step 1: Add a failing execution test**

Create a Pest test that starts `[PHP_BINARY, 'scripts/check-readme-engineering-standards.php']` using `dirname(__DIR__, 2)` as the working directory, calls `mustRun()`, and asserts output contains `README engineering standards are verified.`.

- [x] **Step 2: Confirm the missing script fails**

Run `vendor/bin/pest tests/Unit/ReadmeEngineeringStandardsScriptTest.php --configuration=phpunit.xml`.

Expected: non-zero exit because the script does not exist.

- [x] **Step 3: Implement the checker and Composer command**

The checker reads `README.md`, `phpstan/common.neon`, and the quality, full-test, and coverage workflows. It fails descriptively unless these literals exist:

```php
$contracts = [
    ['README.md', 'PHPStan-level%208'],
    ['README.md', 'parameters%20typed-98.9%25'],
    ['README.md', 'dependencies-audited'],
    ['phpstan/common.neon', 'level: 8'],
    ['phpstan/common.neon', 'param_type: 98.9'],
    ['.github/workflows/code-quality-and-styling.yml', 'composer run check:readme-engineering-standards'],
    ['.github/workflows/code-quality-and-styling.yml', 'composer phpstan'],
    ['.github/workflows/code-quality-and-styling.yml', 'composer audit --locked'],
    ['.github/workflows/test-full.yml', 'laravel: 12.*'],
    ['.github/workflows/test-full.yml', 'laravel: 13.*'],
    ['.github/workflows/coverage-release.yml', '--coverage --min=90'],
];
```

Add `check:readme-engineering-standards` to Composer, invoke the script, and print `README engineering standards are verified.` only when every contract passes.

- [x] **Step 4: Run the focused checker test**

Run `vendor/bin/pest tests/Unit/ReadmeEngineeringStandardsScriptTest.php --configuration=phpunit.xml` and `composer run check:readme-engineering-standards`.

Expected: both exit zero.

### Task 2: Enforce the claims remotely

**Files:**

- Modify: `.github/workflows/code-quality-and-styling.yml`
- Modify: `.github/workflows/coverage-release.yml`

- [x] **Step 1: Run quality checks for both maintained PR targets**

Set pull-request branches to `main` and `1.x`, add the new checker to path filters, and run `composer run check:readme-engineering-standards` after the root-doc check and before `composer install`.

- [x] **Step 2: Enforce 90% coverage before publication**

Add `--min=90` immediately after `--coverage` in the release-coverage Pest command.

- [x] **Step 3: Verify the workflow contract**

Run `composer run check:readme-engineering-standards` and `git diff --check`.

Expected: both exit zero.

### Task 3: Install live and verified README badges

**Files:**

- Modify: `README.md:5-14`

- [x] **Step 1: Replace the tool inventory**

Keep release, PHP, Laravel, and documentation. Use live Shields.io workflow badges for Test matrix and Quality gates, live Codecov coverage, and static contract badges for PHPStan Level 8, parameters typed 98.9%, and dependencies audited. Remove Pint and Rector badges.

- [x] **Step 2: Verify README contracts**

Run `composer run check:root-docs`, `composer run check:readme-engineering-standards`, and `git diff --check`.

Expected: all exit zero.

### Task 4: Commit the verified change

**Files:**

- Modify: `README.md`, `composer.json`, `.github/workflows/code-quality-and-styling.yml`, `.github/workflows/coverage-release.yml`
- Create: `scripts/check-readme-engineering-standards.php`, `tests/Unit/ReadmeEngineeringStandardsScriptTest.php`, this plan

- [x] **Step 1: Run final checks**

Run `vendor/bin/pest tests/Unit/ReadmeEngineeringStandardsScriptTest.php --configuration=phpunit.xml`, `composer run check:root-docs`, `composer run check:readme-engineering-standards`, and `git diff --check`.

- [x] **Step 2: Commit**

Run `git add README.md composer.json .github/workflows/code-quality-and-styling.yml .github/workflows/coverage-release.yml scripts/check-readme-engineering-standards.php tests/Unit/ReadmeEngineeringStandardsScriptTest.php docs/superpowers/plans/2026-07-15-readme-engineering-standards-ci.md && git commit -m "ci: verify README engineering standards"`.
