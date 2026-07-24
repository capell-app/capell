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
composer benchmark:boot -- \
    --profile=production \
    --cache=optimized \
    --iterations=25 \
    --warmups=5
```

`--profile` accepts `full`, `production`, `public`, or `admin`; `--cache`
accepts `manifest`, `optimized`, or `uncached`. The historical positional
iteration form (`composer benchmark:boot -- 10`) remains supported. Use
`--format=json` for retained evidence and `--profiling` to include process,
framework, and per-provider register/boot timings.

Each invocation creates a disposable workspace under the operating system's
temporary directory, copies only the Testbench application skeleton, links the
repository dependencies and workbench, writes its profile configuration and
caches there, warms a CLI OPcache file cache there, and removes the workspace
afterward. It never writes tracked workbench files. Production profiles
explicitly disable Composer package discovery and omit screenshot, Pest, Ray,
Pail, Octane, and other development providers.

The report includes p50, p75, p95, IQR, 10% trimmed mean, Tukey outliers, raw
samples, and a fingerprint covering Git, dependencies, runtime versions,
provider order, package manifests, and configuration-cache state. Warmups are
not included in the reported samples. Primary samples measure in-process
Laravel application creation and bootstrap so operating-system process
scheduling does not distort the production-like boot result. `--profiling`
separately reports total child-process time, process overhead, and provider
register/boot timings.

Establish a baseline only after three 25-sample runs have median spread no
greater than 3% and IQR/median no greater than 10%. Compare revisions with
interleaved runs using the same profile, cache mode, PHP/OPcache settings, lock
file, and provider fingerprint. The Git SHA and manifest hash are expected to
differ when the compared source changes; all environment fields must match.

On the 2026-07-19 Phase 7.2 development machine, seven historical
uncached-discovery boots measured 455.15 ms median before the original guard;
seven cached boots measured 433.82 ms median afterward (21.33 ms, 4.7% lower).
That figure predates these production-like profiles and remains reference-only.
