# README engineering standards CI design

## Goal

Present Capell's engineering standards in the root README only when GitHub Actions enforces the underlying claims.

## Badge model

The badge row will use four live status badges and three configuration claims:

- Release, Test matrix, Quality gates, and Coverage report the current status of their existing public services or GitHub Actions workflows.
- PHPStan Level 8, Parameters typed 98.9%, and Dependencies audited are concise facts. A repository check validates their displayed values against the PHPStan configuration and workflow definitions on every quality run.
- PHP and Laravel compatibility badges, plus the documentation link, retain their current responsibility as product context rather than quality proof.

Pint and Rector remain blocking quality checks but do not receive individual README badges.

## CI enforcement

The PHP Quality workflow will:

- run on pull requests targeting both `main` and `1.x`;
- execute a new `check:readme-engineering-standards` Composer script before dependencies are installed;
- continue running Pint, PHPStan, the PHPStan baseline guard, Composer audit, and Rector dry-run.

The check will fail when README badge values drift from their sources of truth, when the PHP Quality workflow no longer performs Composer audit or PHPStan, when the full test workflow no longer covers Laravel 12 and 13, or when the coverage workflow no longer enforces the declared 90% minimum.

The Coverage workflow will run Pest with `--min=90`, making the coverage floor a GitHub Actions enforcement rather than a local-only Composer convention.

## Implementation boundary

Add one standalone PHP script under `scripts/` and one focused Pest test file. The script has no Composer dependency so it can run before `composer install` in GitHub Actions. Use repository-relative file reads and descriptive errors for failed contracts.

The README will link each factual standard to the relevant existing workflow. It will not claim repository-wide mutation testing or a broader security assessment.

## Verification

Run the focused Pest test, execute the standalone script, run the root documentation check, and inspect the focused diff. The GitHub Actions workflows then provide the authoritative remote enforcement on pushes and pull requests.
