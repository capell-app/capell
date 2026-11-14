# First-Party Package Maintenance

Maintainer notes for the packages in the Capell monorepo and its sibling repository. A third-party extension does not need these: its author checklist is [Extension author checklist](../packages/package-checklist.md).

## Dependencies

- Dependency constraints admit the latest compatible stable release and retain reasonable older majors. Check used APIs before widening, and update both the aggregate and owning package manifests. After installing repository dependencies, `php scripts/check-composer-major-constraints.php` uses Composer Semver to prove that published manifest constraints and literal workflow/provisioning overrides (including resolved workflow matrix values) admit the exact locked version. It rejects invalid or unresolved constraint expressions and enforces the audited major holds in [`scripts/composer-major-exceptions.json`](../../scripts/composer-major-exceptions.json), which record each dependency, held major, reason and owner. Holds never bypass version admission; a locked-major change or an unused hold requires a new decision. The guard cannot prove registry freshness: the locked version may itself lag the latest stable release, and dependencies absent from the lock have no version benchmark.

## Before a first-party release

- Audit dependencies against the package registry for the latest compatible stable releases, including dependencies absent from the lock, and review every audited hold. A passing locked-version guard does not replace this release-time registry audit.
- Run the sibling repo test suite.
- Run `composer lint`.
- Run `composer analyze`.



Activitylog 4 and 5 are supported through `Capell\Core\Support\Activity\ActivityLogCompat`, its `LogOptions` alias and its `LogsActivity` trait. Existing hosts staying on activitylog 4 can upgrade Core without changing their User model, vendor traits, `activities()` relationship, hooks or config. Core's alias files are registered in both Composer manifests and tolerate repeated loading, including optimised and authoritative classmaps.

The installer only patches a conventional `App\Models\User` file containing one class, no additional type or function declarations, no existing activity-logging references and no conflicting import names. Grouped imports and aliases are checked case-insensitively. Existing logging, incomplete Core preparation, custom hooks, trait adaptations and other customised shapes return `Customised` with manual guidance and remain byte-for-byte unchanged. Complete, portable Core preparation is recognised as `AlreadyApplied`; it is never rewritten.

Before manually moving an existing User to activitylog 5, use one `Capell\Core\Support\Activity\LogsActivity` trait and the Core `LogOptions` alias. Return `ActivityLogCompat::options('user', [...])` from `getActivitylogOptions()`, or wrap a portable custom options chain in `ActivityLogCompat::withoutEmptyLogs()`. Review vendor-only calls throughout the file, including `casts()`, panel access methods and helper functions; resolve trait adaptations and custom hooks for both majors. Load the model, persist created and updated activities and query `activities()` against each supported major before upgrading the host dependency.

Admin reads `attribute_changes` first and falls back to legacy `properties`. Run the registered Core migration `2026_10_05_000001_add_attribute_changes_to_activity_log_table` in the host before v5 writes logs: it adds the nullable column without moving historical data or dropping `batch_uuid`, so v4 remains supported. Fresh v5 installations use the consolidated vendor migration; the publisher skips the absent v4 event/batch stubs. Republish and review the installed major's `activitylog` config when upgrading: v5 renames retention/soft-delete keys and moves custom table/connection settings to the configured Activity model. The Testbench preparation script copies the installed config from the unchanged vendor path.

For custom options, replace `LogOptions::defaults()->dontSubmitEmptyLogs()` with `ActivityLogCompat::withoutEmptyLogs(LogOptions::defaults())`. Read tracked values through `ActivityLogCompat::attributeValues()` rather than the removed `Activity::changes()` method.

Static analysis loads the actual compatibility aliases before symbol discovery. Rector preserves absent-major string names and the custom fixture’s public v4 interface scopes. In temporary dependency overlays, pass the owning source autoloader to Rector and verify reflection paths: a different checkout’s method signatures can make its extra-argument rule remove required arguments.

Compatibility fixtures load the actual installed vendor sources; they do not synthesise a missing major. Run the focused activity coverage in separate dependency environments for 4 and 5. Installer coverage executes each accepted User's options method, persists created/updated activities and queries `activities()` in a subprocess, so a reflection-only check cannot hide a runtime failure.

Icon Picker 5 remains held pending inspection of its field API: Admin subclasses `Guava\IconPicker\Forms\Components\IconPicker` and overrides its protected `setUp()` hook. Version 5 metadata alone cannot establish that this extension remains compatible. OpenSpout 5 also remains held: Filament Actions 5.9.0 requires `openspout/openspout:^4.23`, so admitting major 5 in a CI override cannot make it resolvable.
