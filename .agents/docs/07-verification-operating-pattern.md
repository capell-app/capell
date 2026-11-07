## Verification Operating Pattern

Use affected/diff checks while iterating, then run one full PHPStan lane at a time
across App, Core, and companion packages. Keep `maximumNumberOfProcesses: 4`;
parallelism is part of the configured contract and must not be reduced to hide a
timeout, race, or memory failure. Keep result caches warm but branch-isolated,
and clear or prime only the exact cache whose path provenance is known.

The shared PHPStan configuration must define an explicit child-process timeout.
A timeout is an incomplete harness result, not a green or a type error; rerun
with the configured bound and preserve the first diagnostic. Server-backed Pest
shards must use distinct generated databases (including sequential fallback),
while static checks remain database-free. Run `preflight:all` only after focused
gates pass and competing lanes are idle; classify setup, tooling, isolation,
source, and hosted failures separately rather than skipping or baselining them.

**`preflight:all`, `test:all:matrix:local`, and any other whole-tree gate go through
the orchestrator agent, not an arbitrary session** — a focused/diff-scoped run is
always fine to run yourself, but two full runs against the same checkout at once
corrupts shared state and OOM-kills the host. Ask the orchestrator for a full-suite
result rather than running one yourself.

## Format PHP before you stage it: Rector, then Pint, then Prettier

The commit hook runs Rector, Pint and Prettier and stops the commit when any of
them would change a staged file. Letting the hook discover the rewrite means the
commit runs twice, and a rewrite after Pint can be undone by the next tool. Run
the same tools yourself, in the hook's order, on exactly the files you changed
**before** `git add`:

1. `vendor/bin/rector process --no-progress-bar --no-diffs <changed PHP files>`
2. `vendor/bin/pint <changed PHP files>`
3. `npx prettier --write <changed Blade, Markdown, JSON and YAML files>`
4. Stage the explicit paths, then commit.

Rector first, because its rewrites are what Pint and Prettier then normalise.
Build the file list with `git diff --name-only --diff-filter=AM | grep '\.php$'`
and pass it through `xargs` (`… | xargs vendor/bin/rector process …`); in zsh an
unquoted newline-separated variable is one argument, not a list. A hook that
still fails after this is a real finding: read its diagnostics, do not loop.

This applies to every writer lane. A lane that cannot run Git still runs the
three formatters on its changed files before it hands back, so the manager's
commit is a single pass.

Rector sometimes needs a second pass after Pint: run it again until it reports
zero changed files, then run Pint once more.

## Run the gates CI runs before you push, not after

Hosted CI repeats checks the commit hook does not, and each miss costs a push,
a ten-minute matrix and a second round. On 2026-11-05 four consecutive Core
rounds failed on checks a writer could have run in under a minute. Before every
push, run the ones that apply to your diff, from the repository root:

| Your diff touches                                | Run                                                                                                                                                 |
| ------------------------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------- |
| any test file                                    | `composer check:test-weak-assertions` (no multi-argument `->not->toContain(...)`)                                                                   |
| a test class declaration (`final class …Test`)   | `vendor/bin/pest packages/core/tests/Arch/TestPsr4ArchTest.php`: named test classes need a namespace matching the Composer PSR-4 mapping            |
| `packages/**` Markdown, Blade, JSON, YAML or CSS | `npx prettier --check <those files>` (CI checks every packages file, not only the ones you formatted)                                               |
| docs, command names or artisan examples          | `composer check:docs-commands check:docs-spelling check:docs-links`                                                                                 |
| translation keys or UI text                      | `composer check:lang-keys`                                                                                                                          |
| Packages: generated READMEs or docs              | `composer docs:rewrite-readmes` then `composer docs:rewrite-readmes:check`; never add a flag that skips part of the generator to get past a failure |

A branch cut before a guard landed on `main` fails that guard's CI job for lack
of files, not for any defect of its own: merge `origin/main` into the branch
first. Put this table in every writer-lane brief.
