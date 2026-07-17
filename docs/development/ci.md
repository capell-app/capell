# CI And Test Shards

Capell's CI runs code-quality checks and divides the test suite with Pest's native sharding support.

## Pest Shards

`composer test:preflight` runs Pest's parallel suite. Pull-request jobs set `PEST_SHARD` and use `composer test:fast:ci` to select their native Pest shard.

Run `composer test:shards` when the timing manifest needs refreshing. Pest writes `tests/.pest/shards.json` directly.

Pest 4.7 requires a small namespace compatibility patch for this monorepo. Composer applies it after autoload refreshes, and `composer check:pest-shards` fails on unsupported Pest versions so the patch is reviewed and removed once upstream behavior is sufficient.

## Composer Refresh For Screenshot Fixtures

Composer install and autoload refresh matter for screenshot and docs checks because generated Filament/admin fixtures depend on package discovery and Testbench state. CI runs Composer validation and dependency install before quality checks so package providers, screenshot fixtures, and generated docs state use the current lock file rather than stale vendor metadata.

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

To apply Rector, Pint, and Prettier changes before rerunning the same checks, use:

```bash
composer preflight:fix
```

## Next

- [Development commands](commands.md)
- [Docs ownership rules](docs-ownership.md)
