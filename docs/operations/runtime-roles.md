# Runtime roles

Capell fixes one runtime role for the lifetime of each PHP process, before Laravel registers application or Composer package providers. The default remains `combined`, so an existing single-process deployment keeps its current behaviour.

| Role        | Provider graph                                                                                       | Typical process                                |
| ----------- | ---------------------------------------------------------------------------------------------------- | ---------------------------------------------- |
| `combined`  | Core, Frontend, Admin, Installer, Marketplace, Filament, and every enabled extension provider bucket | Existing all-in-one web and worker deployments |
| `public`    | Core and Frontend plus enabled extension `metadata`, `runtime`, `frontend`, and `auth` providers     | Anonymous/public HTTP nodes                    |
| `authoring` | The complete graph, including Frontend so real page previews use the public renderer                 | Admin HTTP nodes and authoring workers         |

The public role excludes Admin, Installer, Marketplace, Filament, TinyEditor, Shield, and other authoring-only providers before registration. It does not register everything and remove providers later. Anonymous HTML safety remains a separate contract: public Blade, fragments, cached responses, static exports, and crawler output must still contain no authoring controls or metadata.

## Host bootstrap contract

Installer applies the `runtime-role-bootstrap-patch` to a stock Laravel `bootstrap/app.php`. Its essential shape is:

```php
use Capell\Core\Support\Runtime\RuntimeRoleBootstrap;
use Illuminate\Foundation\Application;

$app = Application::configure(basePath: dirname(__DIR__))
    // Normal Laravel application configuration...
    ->create();

RuntimeRoleBootstrap::configure($app);

return $app;
```

Custom application bootstraps are reported as customised instead of being rewritten. Apply the equivalent call after `create()` and before a kernel bootstraps the application.

## Build and deploy

Set `CAPELL_RUNTIME_ROLE` in the real process environment, not per request. Build all selected roles into the same immutable release:

```bash
php artisan capell:package-cache

for role in combined public authoring; do
    CAPELL_RUNTIME_ROLE="$role" php artisan optimize
done
```

`capell:package-cache` generates the filtered Laravel package, bootstrap-provider, and service manifests for all three roles. Laravel config, package, service, route, and event caches live below `bootstrap/cache/capell-runtime/<role>/`; they are never shared between public and authoring processes.

Deploy the same code and locked dependencies to each node, then set only that process group’s role:

```env
CAPELL_RUNTIME_ROLE=public
```

Run `php artisan capell:doctor` in each role. Restart PHP-FPM, queue workers, schedulers, and Octane after changing the environment or release. An already-running process never changes role.

Use an authoring or combined scheduler and worker pool for jobs that need Admin, Installer, or Marketplace services. A public worker may run only jobs whose dependencies are in the public provider graph.

## Shared state

Runtime roles are composition boundaries, not separate installations. They use the same database, application key, package lifecycle state, queue backend, shared cache store, sessions where applicable, media, and other persistent storage. Keep those resources consistent across roles and use the existing multi-node shared-cache checks. Only generated framework cache files are role-local.

The authoring role deliberately keeps Frontend registered. Preview requests therefore exercise the same resolver and renderer as public traffic; Admin state must still arrive through authenticated post-load authoring surfaces rather than public HTML.

## Rollback

Build the combined cache alongside the split roles. To roll back the composition boundary, set every process group to:

```env
CAPELL_RUNTIME_ROLE=combined
```

Restart the processes and run `php artisan capell:doctor`. No database, content, package-state, or code rollback is required. An invalid value also falls back to `combined` for availability, but Doctor fails so the configuration error cannot pass deployment admission.

## Boot benchmark

Before admitting a split-role release, run the paired same-host benchmark from the exact revision and dependency lock used for deployment:

```bash
composer benchmark:runtime-roles -- \
    --cache=optimized \
    --iterations=25 \
    --warmups=5 \
    --format=json
```

The report records both roles’ actual p50 and p75 and exits non-zero when public p75 exceeds combined p75. Retain the JSON with the release evidence; compare only runs with matching runtime and dependency fingerprints.
