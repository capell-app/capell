# CI And Test Shards

Capell's CI runs code-quality checks and divides the test suite with Pest's native sharding support.

## Pest Shards

`composer test:preflight` runs Pest's parallel suite. Pull-request jobs set `PEST_SHARD` and use `composer test:fast:ci` to select their native Pest shard.

On ready pull requests, the three SQLite Fast PR gate shards start alongside
matrix resolution. Sentinel waits only for matrix resolution, then the Test All
behaviour, unit and portability jobs wait for sentinel. Fast PR gate remains an
independent failing job: all three fast shards and all Test All cells must pass
for a successful workflow. Draft, Dependabot and targeted-dispatch conditions,
and concurrency cancellation, still apply.

The MySQL Admin Feature cell uses two native Pest shards, named
`Feature - Admin (1/2) - DBmysql` and `Feature - Admin (2/2) - DBmysql`.
Both retain the `Feature` suite and `admin` group and partition that selection
with `--shard=1/2` and `--shard=2/2`. Each has separate JUnit, logs and engineering
metrics, so aggregation counts both shards. Exact dispatches use
`l13-feature-admin-shard-1` or `l13-feature-admin-shard-2`; the local matrix runner
uses the same definitions and cell runner.

Run `composer test:shards` when the timing manifest needs refreshing. Pest writes `tests/.pest/shards.json` directly.

This checkout uses Pest 5 with its native sharding support.

Use `composer test:preflight:serial -- <paths or filter>` for focused selections. With the locked Pest 5.3.x, a filter across the whole tree can report passing tests while its parallel result has `hasTests=false` and exits 1. Retain that exit as a tooling failure; use the serial runner for focused proof and require the full parallel suite and hosted matrix before merge.

## Immutable lock admission

The release-environment Composer constraint checks read sibling locks from the
measured source commits. Set `CAPELL_RELEASE_APP_COMMIT` and
`CAPELL_RELEASE_PACKAGES_COMMIT` to full 40-character commit hashes, alongside
`CAPELL_APPLICATION_ROOT` and `CAPELL_PACKAGES_REPO_PATH`, when running that group
locally. Moving fetched refs or editing a sibling lock cannot change its input.
The App release verifier supplies these pins and includes all three repository
commits in Core's lane cache key; missing or mutable pins fail admission.

## Composer Refresh For Screenshot Fixtures

Composer install and autoload refresh matter for screenshot and docs checks because generated Filament/admin fixtures depend on package discovery and Testbench state. CI runs Composer validation and dependency install before quality checks so package providers, screenshot fixtures, and generated docs state use the current lock file rather than stale vendor metadata.

The documentation receipt checks also need the shared screenshot runner. In a linked worktree, `npm ci` can leave the local `file:../capell-screenshot-tools` dependency pointing at an absent sibling; set `CAPELL_SCREENSHOT_RUNNER_PATH` to the absolute path of a clean, committed runner checkout before `composer preflight:all`. The receipt check validates that checkout's `src/receipt-validator-cli.mjs`; an unavailable validator is a missing prerequisite, not a passing screenshot check.

## Local Checks

Use the narrowest command while changing code:

```bash
vendor/bin/pest packages/frontend/tests/Unit/Cache --configuration=phpunit.xml
```

Before a finished branch, use:

```bash
composer preflight:all
```

`preflight:all` applies repository-wide Rector transformations and Pint formatting automatically, then runs Prettier in check mode. It also runs documentation checks, the root-doc guard, PHPStan baseline growth protection, and Pest. Review and commit any generated changes before pushing; CI asserts that the command leaves a clean checkout, so uncommitted transformations still fail the build.

Preflight runs every selected gate and prints a per-stage summary at the end, even when an earlier independent gate fails. Pest also runs without fail-fast flags so one integrated run reports the complete test failure set. To rerun only named failing gates, pass their stage names after `--`:

```bash
composer preflight -- phpstan tests
composer preflight:all -- docs-links phpstan-baseline
```

An unknown stage exits with the available names. The final preflight exit remains non-zero when any selected gate fails.

On pull requests, the required `PHP Quality (8.4)` job runs the quick static and
formatting stages directly: Composer-major constraints, queue contract,
language-key drift, package dependencies, split-repository health, Agent Schema,
Prettier, ESLint, Pint, and PHPStan. It also runs the PHPStan baseline check,
Composer audit, and the full Rector transformation followed by a clean-tree
assertion. The expensive `tests` stage remains in the required Test All workflow,
which runs the PR Pest shards and the repository's matrix cells rather than
duplicating that work in PHP Quality.

To apply Rector, Pint, and Prettier changes before rerunning the same checks, use:

```bash
composer preflight:fix
```

### Configuration keys need documentation

A new leaf in any `packages/*/config/*.php` must be documented in `docs/development/configuration.md` or classified in `scripts/docs-config-key-classifications.php`. Otherwise `php scripts/check-docs-config-keys.php` (`composer check:docs-config`) fails, and so do the PHP Quality workflow and `DocumentationConfigKeysScriptTest`. Run the script before pushing. Keep the required `PHP Quality (8.4)` and `actionlint` checks present on every pull request to `main`, and read their results before merging.

### Language key audit

`scripts/audit-language-keys.sh` decides a translation key is used as follows:

- Static: a full literal key closed by its quote, `__('capell-x::group.a.b')`.
- Family (credits every matching defined key): `sprintf('capell-x::group.a.%s', $value)` with any prefix or suffix around `%s`, or dot-terminated concatenation, `__('capell-x::group.a.' . $value)`.
- Not matched as a family, so the keys are reported as unused: interpolation, `__("...a.{$value}")`, and underscore-suffix concatenation, `__('...outcome_' . $value)`. Convert such sites to the `sprintf` form.

`scripts/check-language-key-drift.php` gates new drift against a baseline, so a call-site refactor that legitimately changes the dynamic sites needs a baseline `--update`.

## Pest memory limit and parallel flags

- `phpunit.xml` sets `<ini name="memory_limit" value="2G"/>`. PHPUnit applies it in every process, parent and workers, and it overrides `-d memory_limit` on the command line, so no Pest command should pass `-d memory_limit`. Delete the line and workers drop to the host `php.ini` default, so workers dying with `Allowed memory size of 134217728 bytes exhausted` while static stages pass is the 128M default reaching a worker, not a crash.
- `phpunit-coverage.xml` is the second configuration, at 8G, used by the `coverage`, `coverage:blade` and mutation scripts. A second file is needed because the parent and the workers cannot differ, and under `--parallel` the parent deserialises every worker's coverage set. `CoverageWorkflowTest` requires the two files to be identical apart from comments and the `<ini>` line, so edit both together.
- XML comments cannot contain a double hyphen. A flag name such as `--parallel` in a comment makes the file malformed and every Pest run fails to load its configuration. Run `xmllint --noout phpunit.xml` after editing a comment.
- PHPStan is not a Pest process and gets no limit from these files: pass its own memory limit (the `analyze` scripts use `--memory-limit=4G`).

## Hosted workflow triggers

Test All and PHP Quality run on pull requests to `main`, without duplicate
post-merge runs. CodeQL runs weekly or by manual dispatch. Coverage runs on tags
or by manual dispatch. Core Screenshots runs by manual dispatch; screenshots
also run during release deployment. Existing release and dispatch entry points
remain available. The Test All engineering-metrics job is still push-only and
has no automatic trigger after the main push trigger is removed.

Nightly main (`.github/workflows/nightly-main.yml`) runs every night and by
manual dispatch. It calls the Fast PR gate with a fresh latest-dependency update
(no vendor cache) followed by `composer audit`, and calls Test All. A failure on
`main` opens or comments on a single issue labelled `nightly-failure`; the next
passing run closes it.

## Approval environments and workflow credentials

Configure a `release` environment in the monorepo with required reviewers,
prevent self-review and bypass, and allow deployments only from `main`. Store
`SPLIT_APP_ID` and `SPLIT_APP_PRIVATE_KEY` in that environment and remove any
repository or organisation secret copies available to other jobs. Release
dispatches must use `main`; the approved plan source must be an ancestor of the
exact main commit supplying the workflow. Only the publication job receives
write permissions and enters the approval environment.

Each split repository needs a `split-pr-forwarding` environment with required
reviewers, self-review and bypass disabled, and deployment branches restricted
to its protected default branch. Store `SPLIT_PR_FORWARD_TOKEN` there (or the
legacy `ACCESS_TOKEN` fallback) and remove broader copies. Approval is required
for each run, including source updates, before fork-authored files are copied
into a monorepo branch. Review the event's exact source SHA before approving;
the checkout uses that immutable SHA. Per-PR concurrency cancels older pending
runs when the source changes. The forwarder only copies package files and must
never execute code from the source checkout.

Environment names in YAML do not create reviewer or branch protection rules.
Provision and verify these settings before enabling the workflows; an
unprotected environment does not provide an approval boundary. The forwarding
target remains `1.x`, so verify its workflow and secret policies separately.

All read-only workflow checkouts discard credentials immediately after fetching,
including Test All's reusable fast gate, asset formatting, package builds,
coverage, extension audit, browser smoke, Windows verification, CodeQL and
Workflow Lint. The credential contract discovers all monorepo workflows,
split workflows and the forwarding template. Changelog's dedicated write-back
job is the sole exception: its commit action uses persisted checkout credentials
to update `CHANGELOG.md` on `main`, and it contains no repository run steps.

Screenshot, release-smoke, Test All, PHP Quality and Rector repository run steps
receive no PAT, including Test All's framework dependency preparation. Screenshot,
release-smoke, PHP Quality and Rector dependency installation disables npm
lifecycle hooks and Composer scripts/plugins; the root Composer autoload
preparation is then invoked explicitly without credentials using
`composer dump-autoload`, preserving Pest plugin registration, package discovery
and Testbench preparation. PHP Quality refreshes autoload state on both Composer
cache hits and misses.

## Dependabot updates

Dependabot opens one monthly grouped PR per ecosystem. Dependabot PRs run no
hosted jobs and never auto-merge. Combine all open Dependabot PRs into one branch
(for example, `gh combine-prs --query "author:app/dependabot"`, if that extension
is installed, or cherry-pick their commits), run the repository's local gates,
merge once, then close the original PRs.

Run `composer preflight:all` and `composer test:all:matrix:local` before merging
the combined branch.

## Verifying Coverage For A Release

A pull request state is not a test result. Before release work, run Coverage
for the exact release SHA through its tag trigger or manual dispatch, then
inspect every required workflow for that SHA. A workflow
which is still running, failed after executing steps, or was refused before a
runner was assigned is not a green gate. For a failed Coverage run, inspect the
individual shard jobs and retain their logs before rerunning anything:

```bash
gh run list --repo capell-app/capell --branch main --limit 20 \
    --json databaseId,name,status,conclusion,headSha
gh run view <coverage-run-id> --repo capell-app/capell --log-failed
gh api 'repos/capell-app/capell/actions/runs/<coverage-run-id>/jobs?per_page=100' \
    --jq '.jobs[] | [.id,.name,.status,.conclusion] | @tsv'
```

Use the exact hosted failure as the starting point for a focused local
reproduction. Run `composer prepare` and the same Testbench `optimize` command
before the affected Pest path; an already-warm vendor or cache directory can
hide a clean-install failure. Keep the configured parallel worker count when
checking an isolation problem, but do not rerun the whole package matrix just
to rediscover one shard failure.

Two small Testbench details are easy to miss. A route-isolation fixture that
replaces a Laravel `RouteCollection` must leave both the router and
`app('url')` observing the intended collection after replacement and
restoration; refresh name lookups after fluent named-route registration. That
lookup repair does not replace modelling an unavailable Admin resource when a
test is intended to exercise the named-route fallback. Child `list`/`optimize`
commands must boot from the same runtime-role configuration and cache paths as
the parent; verify the expected runtime-role cache file exists after `optimize`,
and make missing settings/provider configuration fail with a useful child
command receipt.

The shard timing warning for `tests/.pest/shards.json` is advisory. Refresh it
only as an intentional, separately reviewed change; updating timings to make a
failure disappear is not a repair. A complete Coverage gate requires every
shard plus the merge/threshold job to finish successfully.

## Database Portability Matrix

Test All owns a focused portability group for every advertised database family:

| Cell                            | Runtime                                          |
| ------------------------------- | ------------------------------------------------ |
| `l13-portability-sqlite`        | SQLite from PHP 8.4                              |
| `l13-portability-mysql-8`       | `mysql:8.0`                                      |
| `l13-portability-mariadb-10-5`  | `mariadb:10.5` through the MariaDB platform seam |
| `l13-portability-postgresql-16` | `postgres:16` through `pdo_pgsql`                |

Every cell runs the same repository-owned Pest group. It proves the complete Core
migration set, database provisioning, install, doctor and upgrade paths, query and
JSON dialects, uniqueness and foreign-key behaviour, and safe backup/restore
process contracts. MariaDB is a separate service and platform assertion rather
than an alias for the MySQL cell. PostgreSQL-specific executable assertions run in
the PostgreSQL cell.

Run one exact committed-head cell locally with:

```bash
composer test:all:matrix:local -- --cell=l13-portability-postgresql-16
```

The local runner creates a detached worktree at the current `HEAD`, prepares the
pinned Laravel/Testbench dependencies, and owns a generated database name, random
host port, and disposable service container. It never uses or mutates a shared
development database. Evidence is written beneath `.test-all-results/`; a failed
or timed-out cell is incomplete evidence, not support proof.

## Next

- [Development commands](commands.md)
- [Docs ownership rules](docs-ownership.md)

## Enqueueing approved PRs by label

The `Merge queue full matrix` check runs the complete Test All matrix on each
merge-group commit and validates a non-empty, passing JUnit report for every
configured cell. Its pull-request run checks the validator; the matrix itself
runs on the merge-group commit. To enforce this check, add it to the existing
main-branch required checks only after this workflow is available on `main`
and the queue is clear. Keep the existing required checks in place.

Adding `ready-to-merge` to an open PR targeting `main` authorises the queue to
update its branch and merge it after checks pass, without a further per-PR chat
approval. This is an explicit exception to the normal PR merge approval rule.
The worker scans all labelled PRs, including existing labels, in PR creation order.
It excludes drafts and `awaiting-merge` PRs, and handles one PR at a time. If
its branch is behind, the worker updates it from `main` and waits for checks on
the new head. When checks pass, it adds the PR to GitHub's merge queue and waits
for that queue to finish before processing another PR. It runs when the label is
added, every 15 minutes and by manual dispatch; GitHub may delay scheduled runs.

If a PR's checks fail, it conflicts, or GitHub removes it from the merge queue,
the worker removes `ready-to-merge` and continues with the next candidate. Fix
the problem, review the new final diff and reapply the label to retry. PRs from
forks must be updated manually. Unexpected API failures stop the scan and retry
later rather than being treated as test failures. A previously queued or merged
newer PR cannot be reordered when an older PR is labelled later.

The label authorises the worker to process the PR's current head, including an
automatic update from `main`. Remove it before pushing changes that should not
be merged; reapply it when the PR is ready. Removing the label does not dequeue
an existing PR: remove it from GitHub's queue separately. Enqueueing can lead to
an automatic merge and there is no direct-merge fallback. Monitor the workflow
and queue.

An administrator must enable the `main` merge queue and install a dedicated
GitHub App on this repository with Contents write, Pull requests write and Merge
queues write permissions, without bypass rights. Store its ID and private key
in `MERGE_QUEUE_APP_ID` and `MERGE_QUEUE_APP_PRIVATE_KEY` repository secrets.
The workflow creates a short-lived installation token for each run. The
`ready-to-merge` label must exist. Verify the App can enqueue and required
checks run before relying on the queue. `actionlint` and `PHP Quality (8.4)` run
on `merge_group`; review branch protection and rulesets for any additional
required checks and ensure each reports on `merge_group`. Hosted Actions billing
or credential restrictions can prevent the worker from running; local checks
do not produce the required merge-group status.
