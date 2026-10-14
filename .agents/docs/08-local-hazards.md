## Local Hazards

- Audit all three JavaScript locks: the root, `packages/admin`, and
  `packages/frontend`. Typography's scoped parser override fixes the selector
  complexity advisory; the CLI's scoped Parcel watcher override removes its
  unpatched brace dependency. Remove each override when its parent requires a
  safe version. Verify native watch rebuilds and the owning asset builds after
  changing these locks; a root audit alone misses package assets.
- This primary checkout stays on latest `main`, clean, when not actively in use
  (canonical version and the incident behind it: capell-app's `AGENTS.md`, "Standing
  hygiene: primary checkouts stay on main; pin worktree bases"). Return it to `main`
  the moment any branch work here lands or pauses — a stale branch left checked out
  gets silently inherited by the next worktree provisioned from it.
- The supported runtime is PHP 8.4. Do not interpret failures from a different host
  PHP as release evidence.
- Provision a new worktree completely before the first gate, in this order:
  `./capell up -d`, `./capell composer install`, `npm ci --no-audit --no-fund`.
  `bash scripts/init-worktree.sh` refuses the Docker path and exits before its own
  `node_modules/` step, so the npm step is never done for you; a preflight that
  stops on "node_modules/ is missing" is an unprovisioned worktree, not a result.
  `bash scripts/init-worktree.sh --host-only` does not run `npm ci` either, and
  `packages/admin` carries its own `package.json` and lock: run `npm ci --no-audit
--no-fund` in the repository root and again in `packages/admin` before building
  bundles (`npm run build:js` there). The lock pins esbuild; a different version
  rewrites unrelated published bundles, so regenerate with the pinned one, never
  hand-edit `packages/admin/publishes/build`, and restore bundles you did not mean
  to change. Automated workers cannot run `npm ci` in their sandbox: provision
  before handing them the worktree and have them stop, not work around it, when a
  module is missing.
- Focused Pest in the container: `./capell pest <path> --configuration=phpunit.xml`.
  The wrapper runs it as the `capell` user with SQLite `:memory:`. Never use raw
  `./capell compose exec app vendor/bin/pest`: it runs as root with the container's
  MySQL environment, so it trips the package database guard ("Refusing to run
  Capell package Pest tests against database") and, as root, falsely fails every
  "unwritable file" test (`PhpFileEditorTest`, `EnvFileEditorTest`,
  `RunInstallPreflightChecksActionTest`). `./capell test` takes no path.
- Host worktrees must set `CAPELL_SCREENSHOT_RUNNER_PATH=<shared runner checkout>`
  before screenshot-receipt checks or full preflight. `npm ci` leaves the declared
  relative `file:` dependency pointing beside the worktree, where the shared runner
  is absent; the verifier supports this explicit path without changing either lock.
- Host-only worktrees: `bash scripts/init-worktree.sh --host-only`. Never symlink `vendor/` or
  `vendor/composer/`: Composer then resolves Capell classes from the primary
  checkout and green tests exercise the wrong source. Verify a changed class with
  `ReflectionClass::getFileName()`; use a real `composer install` for authoritative
  full-suite proof when the hybrid vendor limitation is relevant.
- Do not run dependency-mutating Composer commands in a hybrid worktree. Its
  third-party package directories are intentionally shared with the primary
  checkout.
- `composer preflight` requires the pinned `node_modules`; run `npm ci` when it is
  absent. `preflight:all` intentionally applies Rector and Pint, so review its diff.
- `composer test:all:matrix:local` verifies the committed exact `HEAD` in isolated
  worktrees/containers. It does not include uncommitted changes.
- Host and container PHPStan caches must remain separate. `phpstan.neon` uses
  `var/phpstan/full`, while Docker masks `var/`; a cache containing paths from the
  other execution context can raise `phpstorm-stubs` “is not a file” internal
  errors. Confirm the cached path provenance before clearing only that cache.
- Keep local Composer path repositories out of public `composer.json` and
  `composer.lock`; `composer check:composer-paths` enforces this.
- `composer analyze -- <path>` (a raw path argument on the full config) reports
  false "stale ignore" errors: `ignoreErrors` entries in `ignore-errors.neon`
  whose `path`/`paths` sit outside the given argument are flagged as unmatched
  even though the underlying error still occurs — `reportUnmatchedIgnoredErrors`
  only sees the CLI-narrowed report set, not the full tree the pattern is
  written against. Verified 2026-09-04: `composer analyze -- packages/core`
  reported 4 unmatched patterns targeting `packages/admin` files; `composer
analyze` (full, unscoped) confirmed all 4 still fire as real errors (16
  errors total once the `packages/admin` call sites are back in scope). Use
  `composer analyze:diff -- <path>` for any path-scoped run instead —
  `phpstan/diff.neon` sets `reportUnmatchedIgnoredErrors: false`, the same
  relaxation `source.neon`/`tests.neon` already apply for the same reason.
  Only the full `composer analyze` can prove an ignore pattern has actually
  gone stale.

Use available Boost capabilities and reusable skills as live tooling. Do not copy
generated Boost guideline dumps or static skill inventories into this file.
