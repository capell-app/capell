# Deploys, cache refresh and production pitfalls

A runbook for the parts of a release that fail quietly. Server setup is in [Going live](going-live.md) and [Web server configuration](web-server.md); this page covers what to do on each deploy and the traps around it.

## Deploy sequence

On each node, after the new release is in place:

```bash
php artisan migrate --force
php artisan capell:runtime-refresh
```

`capell:runtime-refresh` rebuilds the Capell package cache, clears compiled views, rebuilds the configuration and route caches, warms critical pages, runs Capell Doctor, and finally runs `queue:restart` so workers pick up the release. It exits non-zero if any stage fails, and a stage that throws is recorded as failed without stopping the others. Prefer it to calling the cache commands yourself. The configuration stage only rebuilds the cache if the configuration was already cached; an uncached install stays uncached.

Then reload PHP-FPM (or Octane), and refresh public HTML as described below.

## Stale compiled views after a release switch

Compiled Blade views live under `storage/framework/views`, which is often shared between releases. After switching `current` to a different release, or rolling back, a stale compiled view can render the wrong template or print literal placeholder text. `capell:runtime-refresh` clears views; if you switch releases by hand, run `php artisan view:clear` as the application user straight after the switch, then clear the public HTML cache.

## Refresh public HTML in the right order

When the optional `capell-app/html-cache` package is installed, public pages are served as stored HTML. Changing templates or shared content does not change them until they are regenerated.

```bash
php artisan view:clear
php artisan capell:html-cache:clear
php artisan capell:html-cache:process-stale --suppress-inline-edge-purge
```

1. `view:clear` first, so regeneration renders with the new templates.
2. `capell:html-cache:clear` only **marks** every cached URL stale. It prints `Marked N URL(s) stale` and the old HTML keeps being served until the next step. Pass `--process` to run the next step inline.
3. `capell:html-cache:process-stale` re-renders stale URLs (`--limit=` caps the batch). `--suppress-inline-edge-purge` leaves edge purging to you so it happens after verification. Read the summary for failed or deferred URLs.
4. **Verify the origin** before purging anything.
5. Purge the edge, only if one is configured.

The commands need a queue worker for the jobs they dispatch, and invalidation in `scheduled` mode relies on the scheduler running `capell:html-cache:process-stale`. The html-cache package's invalidation guide covers the invalidation modes and edge drivers.

### Verify on the canonical URL

Request the canonical public URL, with no query string and no session cookie:

```bash
curl -sS -D - https://www.example.com/some-page | grep -Ei '^(cache-control|cf-cache-status|age|x-frontend-cache):'
```

A query string can bypass or key differently from the stored page, and a signed-in session bypasses it entirely, so either can look fresh while the cached page is stale. To skip the edge, resolve the hostname to the origin with `curl --resolve`, as shown in [Going live](going-live.md#telling-the-edge-apart-from-the-origin).

### Purge the edge last

Purge Cloudflare only once the origin serves the new content; the order and Cache Rules are in [Making Capell fast](making-capell-fast.md#put-cloudflare-in-front). Do not rely on per-URL purge alone for a whole-site change. A URL list can miss variants and pagination, and a purge that is accepted but incomplete leaves some pages stale for the rest of their TTL. After a deploy that changes shared layout or assets, purge the zone, then confirm a `MISS` followed by a `HIT`. `php artisan capell:html-cache:edge-purge:verify --driver=cloudflare` checks that the purge driver and credentials are configured without purging.

## Maintenance mode caveats

`php artisan down` makes Laravel return 503 for web requests. Two side effects catch people out:

- Queue workers stop processing jobs while the application is down unless started with `--force`, and `schedule:run` skips scheduled tasks unless they opt in with `runInMaintenanceMode()`. A health check that depends on a worker or scheduler heartbeat cannot pass while the site is down.
- During [Lockdown](lockdown.md), Laravel's maintenance bypass secret and cookie are ignored for public frontend traffic.

Keep the window short, run `php artisan up` before verifying queue and scheduler health, and do not treat a worker or scheduler warning raised during maintenance as a deploy failure until you have re-checked after `up`.

## Rollback

Switch `current` back to the previous release, then run the same steps as a deploy: `view:clear`, `capell:runtime-refresh` (it rebuilds the package cache for the code now running), reload PHP-FPM or Octane, and refresh the HTML cache. Database migrations are not reversed by switching code; see [Upgrades](upgrading.md#rollback) and take a [backup](backups.md) first.

## Failed jobs

Queue and scheduler supervision is in [Going live](going-live.md#keep-the-queue-and-scheduler-running). Failed jobs stay in `failed_jobs` until you act:

```bash
php artisan queue:failed
php artisan queue:retry <uuid>
php artisan queue:forget <uuid>
php artisan queue:flush
```

Retry only after fixing the cause. Use `queue:forget` for a single stale row and `queue:flush` to empty the table. Any monitoring that treats a non-empty `failed_jobs` table as unhealthy, including a post-deploy gate, will keep failing on a stale row until it is forgotten, so review the list before a release rather than after.

## Configuration caching and `.env`

Once `config:cache` has run, Laravel stops reading `.env`. `env()` called outside `config/*.php` returns `null`, and editing `.env` has no effect until the cache is rebuilt. This affects ad hoc `artisan` commands too: they load the cached configuration of the release they run from, not the file you just edited.

- Change a value in `.env`, then run `php artisan config:cache` (or `capell:runtime-refresh`) and restart workers and PHP-FPM.
- To run one command against a different configuration without touching the cache, point Laravel at a cache path that does not exist: `APP_CONFIG_CACHE=/tmp/no-config.php php artisan config:show app.url`. Laravel then loads configuration from the files and `.env`.
- Run `artisan` as the same user as PHP-FPM so cache and log files are not created with the wrong owner.

Values stored in the database, such as Admin **Settings**, are separate from `.env`. Where Admin owns a value, its stored setting takes over and the matching env var is silently ignored (the activity-collection keys are the documented example in [Configuration](../development/configuration.md)). Clearing the config cache does not change them.

## Scanners, 419 and 429 responses

Public sites attract automated probing. Most of it is harmless, but know what it looks like:

- **`419` on `/livewire/update`.** Livewire returns a generic, empty `419` when a request has an expired CSRF token or session, or when a payload is corrupt, tampered with, or tries to change a locked property. A single user seeing it usually has an expired page; a stream of them with no matching sessions is scanner traffic. Do not "fix" it by loosening CSRF.
- **`404` and `405` floods for paths like `/wp-login.php`.** Answer known-bad paths in nginx so they never boot PHP, and rate-limit at the edge with a Cloudflare rule. Keep these out of your PHP-FPM budget; see [Sizing PHP-FPM](php-fpm-sizing.md).
- **`429 Too Many Requests`.** Capell registers a few Laravel rate limiters: the agent endpoints (`capell-agent`, `capell.agent.rate_limit` requests per minute per host and IP, default 60) and the reporting health endpoint (30 per minute per client IP, see [Operational reporting](reporting.md)). Behind a proxy, the limiter keys on the client IP, so [trusted proxies must be configured](going-live.md#behind-cloudflare) or every visitor appears to share the proxy's address and gets limited together.
- **Lockdown.** If probing becomes an incident, use [Lockdown](lockdown.md).

## Response headers and the edge

Anonymous public pages are cacheable. Capell and its packages use `private`, `no-store`, or no public header for responses that must not be shared (preview, authoring, language redirects, and similar). A signed-in session cookie must bypass both the static cache and the edge. The header values to send for cached pages, the Cloudflare cache and bypass rules, and the `MISS`/`HIT` checks are in [Making Capell fast](making-capell-fast.md#put-cloudflare-in-front).
