# Making Capell fast

Capell is fastest when most public requests never start PHP. Treat performance as a
three-tier serving path, then size the PHP runtime for the smaller set of requests that
reach it.

## The three serving tiers

| Tier | Serves | Expected path |
| --- | --- | --- |
| CDN edge | Anonymous public HTML already stored near the visitor | Cloudflare responds without contacting your server |
| Web server | Generated HTML on the `page_cache` disk | nginx or Apache reads a file without starting PHP |
| PHP runtime | Admin, authenticated, personalised, uncached, and write requests | PHP-FPM or Octane boots or reuses Laravel |

Keep the tiers in that order. Octane can reduce the cost of the third tier, but it
cannot beat a safe edge hit or a local static file.

## Serve generated HTML before PHP

The package that owns the page cache defines its filename and invalidation contract.
Use its documented mapping rather than guessing from the public URL. A typical nginx
shape is:

```nginx
set $page_cache_root /absolute/path/to/public/page-cache/https.example.com;

location = / {
    add_header Cache-Control "max-age=300, public, s-maxage=1800, stale-while-revalidate=86400" always;
    try_files $page_cache_root/pc__index__pc.html /index.php?$query_string;
}

location / {
    add_header Cache-Control "max-age=300, public, s-maxage=1800, stale-while-revalidate=86400" always;
    try_files $uri $uri/ $page_cache_root$uri.html /index.php$is_args$args;
}
```

This is only the final lookup. Before it, bypass the static path for anything that can
vary by visitor or request: methods other than `GET` or `HEAD`, query strings that are
not explicitly cache-safe, Laravel session cookies, authorization headers, signed or
preview URLs, and Livewire or other navigation requests. Keep the same bypass contract
at the web server, package middleware, and CDN.

The example policy means:

- browsers may reuse the response for five minutes (`max-age=300`);
- a shared cache may retain it for 30 minutes (`s-maxage=1800`);
- the shared cache may serve the previous response for up to one day while it
  revalidates (`stale-while-revalidate=86400`).

Choose shorter values when content must propagate faster. Never add a public policy to
authenticated, personalised, preview, or authoring responses.

## Put Cloudflare in front

1. Proxy the site's DNS record through Cloudflare.
2. Create a Cache Rule for the site's anonymous public `GET` and `HEAD` routes.
3. Exclude admin, API, authentication, account, checkout, Livewire, health, preview,
   and signed paths.
4. Exclude requests containing the configured Laravel session cookie.
5. Set cache eligibility to **Eligible for cache**.
6. Set Edge TTL to use the origin `Cache-Control` header when present and bypass when
   it is absent.
7. Set Browser TTL to respect the origin.
8. Add a separate **Bypass cache** rule for the session cookie as a defence in depth.

For a site using the default cookie, the bypass expression is:

```text
(http.host eq "www.example.com" and http.cookie contains "laravel_session=")
```

Do not use a rule that force-caches every HTML response or ignores origin cache
control. Capell and its packages use `private`, `no-store`, and missing public headers
to keep unsafe responses out of shared caches.

Give the application a least-privilege Cloudflare API token with Cache Purge permission
for this zone. Configure the page-cache-owning package's Cloudflare purge adapter with
that token and the zone ID. Content invalidation should purge the affected URLs or cache
tags; deploys may purge the whole zone when a precise list is unavailable.

The safe deploy order is:

1. switch to the new release;
2. generate and warm the new origin HTML;
3. purge Cloudflare;
4. verify the next anonymous request is a `MISS` and the following request is a `HIT`.

Purging before the origin is warm creates an avoidable burst of PHP work and can refill
the edge from stale or incomplete origin state.

Verify both sides:

```bash
curl -sS -D - -o /dev/null https://www.example.com/ \
    | grep -Ei '^(cache-control|cf-cache-status|age|set-cookie):'
curl -sS -D - -o /dev/null https://www.example.com/ \
    | grep -Ei '^(cache-control|cf-cache-status|age|set-cookie):'
curl -sS -D - -o /dev/null \
    -H 'Cookie: laravel_session=cache-bypass-check' \
    https://www.example.com/ \
    | grep -Ei '^(cache-control|cf-cache-status|age|set-cookie):'
```

The anonymous pair should settle at `MISS` then `HIT` with no `Set-Cookie`. The
session-bearing request must remain uncached; Cloudflare may label that response
`BYPASS` or `DYNAMIC`.

## Tune a low-powered server

Start with PHP-FPM unless measurements show that dynamic requests dominate. A
two-core, 4 GB host can run Capell reliably when the database and PHP worker counts are
bounded and at least 500 MB remains available during peak work.

For PHP-FPM, use `pm = ondemand` so idle sites do not retain a full pool. Set
`pm.max_children` from measured peak resident memory rather than CPU count alone:

```text
safe children = floor(PHP memory budget / measured peak worker RSS)
```

Start with a small `pm.max_children`, a short `pm.process_idle_timeout`, and
`pm.max_requests` between 250 and 1000 so gradual leaks are recycled. A queue worker is
another long-lived PHP process and belongs in the same budget.

Enable OPcache for the web SAPI. For an immutable release, a practical starting point
is:

```ini
opcache.enable=1
opcache.memory_consumption=192
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=20000
opcache.validate_timestamps=0
```

Restart or reload the PHP runtime after switching releases. If files are edited in
place, keep timestamp validation enabled instead of using the immutable-release
setting.

Use this as an initial 4 GB allocation, then replace it with measured high-water marks:

| Area | Starting budget |
| --- | ---: |
| Operating system, web server, and monitoring | 700-1000 MB |
| Database | 600-1000 MB |
| PHP web and queue workers | 900-1400 MB |
| Redis and other local services | 100-300 MB |
| Uncommitted headroom | at least 500 MB |

Do not fill every budget simultaneously. Database imports, cache generation, image
work, backups, and deploys create short peaks; memory caps and headroom turn those peaks
into visible failures instead of host-wide outages.

## Where Octane fits

[Laravel Octane](octane.md) keeps Laravel booted for tier-three requests. RoadRunner is
a good fit when the host uses non-thread-safe PHP and does not provide the Swoole
extension. Begin with one or two workers, cap the service, set a finite maximum request
count, and compare dynamic-route latency and steady memory with PHP-FPM before
cutting over.

Two Octane workers can each retain an application-sized memory image, so a faster p50
can cost more idle memory than an on-demand FPM pool. Keep the static and Cloudflare
tiers in front of Octane, and retain the FPM configuration for immediate rollback.

On every deploy, run:

```bash
php artisan capell:runtime-refresh
php artisan octane:reload
```

The refresh rebuilds Capell's runtime state; the reload ensures workers boot the new
release. See [Running Capell on Laravel Octane](octane.md) for the extension reset
contract and [Web server configuration](web-server.md) for static-cache and multi-node
requirements.
