# Blade View Coverage

![Capell Blade View Coverage screenshot](../images/admin-dashboard.png)

Capell uses [`capell-app/pest-plugin-blade-coverage`](https://github.com/capell-app/pest-plugin-blade-coverage) to check package Blade views separately from PHP line coverage. PHP coverage intentionally ignores `resources/views`, so this plugin records views that Laravel actually renders during Pest runs.

The check is ratcheted. Existing uncovered views are stored in `tests/BladeCoverage/baseline.json` with content hashes. CI fails when a new package view is not rendered by tests, or when an existing uncovered view changes without gaining render coverage.

## Commands

Run the Blade view coverage gate:

```bash
composer coverage:blade
```

Refresh the baseline after intentionally accepting current uncovered views:

```bash
vendor/bin/pest --blade-coverage --blade-coverage-update-baseline --parallel --configuration=phpunit.xml
```

The config lives at `tests/blade-coverage.php` and currently targets:

```php
packages/*/resources/views/**/*.blade.php
```

## Expectations

- Prefer route, Livewire, or direct view tests that render the Blade file.
- Includes, partials, and component views count when Laravel renders them.
- Source-only assertions with `file_get_contents()` do not count as coverage.
- Do not add broad excludes for hard-to-test views. Keep the baseline as the ratchet and add focused tests when changing views.

## Next

- [Development](index.md)
- [Command index](commands.md)
