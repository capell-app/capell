# Boot regression guards

Production HTTP and worker boots load extension metadata from
`bootstrap/cache/capell-package-manifests.php`. Generate it during deployment
with `php artisan optimize` or `php artisan capell:package-cache`. A missing or
invalid cache fails a non-console boot with a remediation message; console
commands retain discovery so installation and cache recovery remain available.

Three `eloquent.*: *` listeners are intentionally retained for created, updated,
and deleted events. They support third-party `Page` subclasses used as error
pages. `ErrorPageModelInvalidationObserver` immediately rejects unrelated model
types, translation owners, and timestamp-only updates. The boot architecture
test pins this as the complete first-party wildcard set.

Run the repeatable isolated-process benchmark with:

```bash
composer benchmark:boot -- 10
```

The command warms the Testbench manifest cache when needed, boots the application
in non-console mode N times, and reports the sorted samples and median. On the
2026-07-19 Phase 7.2 development machine, seven uncached-discovery boots measured
455.15 ms median before the guard; seven cached boots measured 433.82 ms median
afterward (21.33 ms, 4.7% lower). Treat local numbers as a regression ledger,
not a portable service-level objective.
