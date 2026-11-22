## Verification Commands

Write tests that assert behaviour, never implementation, line positions or SHAs: see
[Test behaviour, not implementation](../../docs/packages/testing-packages.md#test-behaviour-not-implementation).

Broad tests and preflights use the [host release verification gate](../../docs/release-verification-lock.md). Use this checkout's wrapper for Docker commands; focused single-file runs remain available while a release holds the gate.

- Focused Pest: `./capell pest packages/<package>/tests/path/ToTest.php --configuration=phpunit.xml`
  (host: `vendor/bin/pest ...` with PHP 8.4)
- Changed-file formatting: `composer lint:changed`
- Source analysis while iterating: `composer analyze:source`
- Ad-hoc path-scoped analysis (e.g. one package): `composer analyze:diff -- <path>`.
  Never pass a path to plain `composer analyze` — see Local Hazards.
- Standard repository gate: `composer preflight`
- Full repository gate before completion: `composer preflight:all`
- Local equivalent of the hosted Test All topology:
  `composer test:all:matrix:local`
- Upgrade path from the last published release (run by the merge queue, on `main` and
  nightly, not per PR): `heavy run -w 2 upgrade-path -- scripts/upgrade-path.sh`. Run it
  locally for a change to migrations, upgrade steps or the upgrade pipeline. A removal of
  data that is intended is declared, with its reason, in
  `scripts/upgrade-path/allowed-changes.json`.
- Extension contracts: `composer check:extension-surfaces` and
  `composer check:stable-extension-api`
- Documentation contracts: `composer docs:check` (spelling, links, orphans,
  requirements, commands, env, config and screenshots), plus
  `composer check:root-docs` and `php scripts/check-readme-engineering-standards.php`
- New development documentation changes generated distribution exclusions. Run
  `php scripts/sync-split-repository-health.php`, then its `--check` mode before committing it.

Run the narrowest relevant command first. Rendering/cache changes need focused
Frontend safety tests; migration, config, constraint, or public-extension changes
also need both extension-contract checks.
