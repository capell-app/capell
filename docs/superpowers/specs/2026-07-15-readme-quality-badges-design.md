# README quality badges design

## Goal

Make the repository's engineering safeguards immediately legible in the root README without overstating what CI verifies or adding new external services.

## Scope

Update only the root README badge row.

Keep the existing release, test, coverage, PHP, Laravel, and documentation badges. Replace the generic PHP Quality badge with explicit quality signals that link to the existing PHP Quality workflow:

- PHPStan level 8;
- Composer dependency audit;
- Pint style check;
- Rector dry-run.

The row will remain compact and ordered by relevance: release, static-analysis and security evidence, test and coverage status, code-quality enforcement, then platform and documentation context.

## Sources of truth

The badges must remain accurate against the repository configuration:

- `phpstan/common.neon` configures PHPStan level 8;
- `.github/workflows/code-quality-and-styling.yml` runs PHPStan, the no-growth baseline check, Composer audit, Pint, and a Rector dry-run;
- `.github/workflows/test-full.yml` runs the Laravel 12 and 13 test matrix;
- `.github/workflows/coverage-release.yml` uploads coverage to Codecov.

The PHPStan, Composer audit, Pint, and Rector badges will link to the PHP Quality workflow. Test and coverage badges retain their existing workflow and Codecov targets.

## Non-goals

- Do not introduce a new CI workflow, badge provider, dependency, or metrics service.
- Do not advertise configured coverage or type-coverage thresholds as externally verified live measurements.
- Do not change the contributor commands, CI behavior, or requirements documentation.

## Verification

Run the repository's root README validation after the edit. Confirm the badge URLs use the existing `capell-app/capell` workflow and documentation targets.
