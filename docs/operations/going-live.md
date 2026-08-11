# Going live

This page takes a working Capell installation and puts it on a public domain.

The [install guide](../getting-started/install.md) ends with a site that runs. It assumes
you already have a host, a domain pointed at it, TLS, and a supervised queue. This page
is where those come from. Work through it in order — each section ends in something you
can check before moving on.

If you are adding Capell to an application that is already live on its domain, you can
skip to [Behind a proxy or CDN](#behind-a-proxy-or-cdn) and
[Keep the queue and scheduler running](#keep-the-queue-and-scheduler-running); those two
are the sections that catch existing deployments out.

## Choose a hosting shape

Decide this first. It determines whether Capell may write to its own release directory,
which in turn decides whether installing an extension or rebuilding assets from the admin
UI works at all.

| Hosting shape                                        | `CAPELL_RELEASE_ROOT_MODE` | Admin-UI extension installs and asset rebuilds |
| ---------------------------------------------------- | -------------------------- | ---------------------------------------------- |
| Shared hosting, or a checkout you deploy into in place | `mutable`                  | Possible, if the process user may write and Node is present |
| A VPS you administer, deploying in place             | `mutable`                  | Possible, same conditions                      |
| Docker image or other read-only container            | `immutable`                | No — do this at image build time               |
| Atomic releases behind a `current` symlink (Forge, Ploi, Deployer, Envoyer) | `atomic` | No — do this at build time                     |
| Laravel Cloud or similar managed platform            | `immutable`                | No — do this at build time                     |

Set it to match reality, not to what you wish were true. Capell detects a symlink
component inside a root declared `mutable` and blocks the write; do not work around that
by making versioned release directories writable, because a long-running request can then
modify a release you have already promoted away from.

The full description of each mode, and the paths the installer needs to write, is in
[Install-time write permissions](../getting-started/install.md#install-time-write-permissions).

If you chose `immutable` or `atomic`, plan now for how extensions and frontend assets get
built, because the admin UI will refuse to do it later. Both are build-time steps. See the
[hosting checklist](../getting-started/install.md#hosting-checklist) and
[Marketplace hosting](marketplace-hosting.md).

## Point DNS at the site

Capell resolves a request to a **Site** by its domain, so three things must agree:

1. the DNS record a visitor resolves;
2. `APP_URL` in the application environment;
3. the domain configured on the Capell Site record in Admin.

When they disagree, pages either 404, redirect somewhere unexpected, or fail with
[`UrlMissingSiteDomainException`](troubleshooting.md#site-domain-missing) when Capell
cannot build a URL for a page. That exception is almost always a domain-alignment problem
rather than a content problem.

Create the records:

| You want visitors at | Record                                                     |
| -------------------- | ---------------------------------------------------------- |
| `example.com` (apex) | `A` to the server's IPv4, and `AAAA` if you serve IPv6      |
| `www.example.com`    | `CNAME` to `example.com`, or its own `A`/`AAAA`             |
| Behind a CDN         | Whatever the CDN requires — for Cloudflare, a proxied ("orange cloud") record |

Pick one canonical hostname and redirect the other to it at the web server. Serving the
same content on both apex and `www` splits caches and confuses canonical URLs.

Before a cutover from an existing site, lower the record's TTL to 300 seconds a day
ahead, so a mistake costs you five minutes rather than the old TTL. Raise it again once
traffic is stable.

Check propagation before continuing:

```bash
dig +short example.com
dig +short www.example.com
```

For a multi-site installation, every Site needs its own resolvable domain and its own
entry in the TLS certificate. Add them all before launch — a Site whose domain does not
resolve will not serve, and its pages cannot generate URLs.

## Terminate TLS

Capell assumes HTTPS. `APP_URL` should be `https://` from the start; the installer's
`--url` flag takes the canonical HTTPS URL.

### On a host you administer

Issue a certificate with Certbot, which edits the nginx configuration and installs a
renewal timer:

```bash
sudo certbot --nginx -d example.com -d www.example.com
```

Confirm renewal works before you rely on it:

```bash
sudo certbot renew --dry-run
```

For a multi-site installation, pass every Site domain in the same `-d` list so one
certificate covers them all.

The resulting server block should redirect port 80 and serve TLS on 443:

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name example.com www.example.com;

    return 301 https://example.com$request_uri;
}

server {
    listen 443 ssl;
    listen [::]:443 ssl;
    http2 on;
    server_name www.example.com;

    ssl_certificate     /etc/letsencrypt/live/example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/example.com/privkey.pem;

    return 301 https://example.com$request_uri;
}

server {
    listen 443 ssl;
    listen [::]:443 ssl;
    http2 on;
    server_name example.com;

    root /var/www/example.com/current/public;

    ssl_certificate     /etc/letsencrypt/live/example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/example.com/privkey.pem;

    # Editors upload media through the admin. nginx defaults to 1m, which
    # rejects most photographs before PHP ever sees them.
    client_max_body_size 32m;

    # Capell emits the other security headers itself. Read the Security
    # headers section before adding more add_header directives here.
    add_header Strict-Transport-Security "max-age=31536000" always;

    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        try_files $uri =404;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param HTTPS $https if_not_empty;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

`client_max_body_size` must be at least as large as PHP's `upload_max_filesize` and
`post_max_size`, or nginx rejects the upload with a `413` before PHP can report a useful
error. Set all three together and restart both services.

Add `Strict-Transport-Security` only once HTTPS is confirmed working on every hostname
you serve. It instructs browsers to refuse plaintext for the given duration, and they
will honour that even if you later need to undo it. Leave `preload` off unless you have
read what submitting to the preload list commits you to.

Emit HSTS from one layer only. Capell can emit it itself — see
[Security headers](#security-headers) — and a header set in both places is sent twice.

The rest of the server configuration — compression, static-asset headers, and the
optional static HTML cache — is covered in
[Web server configuration](web-server.md) and
[frontend server configuration](../../packages/frontend/server-config.md).

### Behind Cloudflare

Set the zone's SSL/TLS encryption mode to **Full (strict)**, and give the origin a
certificate Cloudflare will accept — either a normal Certbot certificate or a Cloudflare
Origin Certificate.

Do not use **Flexible**. Flexible means Cloudflare speaks HTTPS to the visitor and plain
HTTP to your origin. Laravel then sees an insecure request, redirects to the HTTPS URL,
Cloudflare forwards that new request over HTTP again, and the site enters an infinite
redirect loop. This is the single most common way a working Laravel site breaks on the day
it goes behind Cloudflare.

**Full (strict)** also requires the next section.

## Behind a proxy or CDN

Any time something else terminates TLS — Cloudflare, a load balancer, an ingress
controller — the request reaching PHP arrives over plain HTTP from the proxy's IP.
Until you tell Laravel to trust that proxy, it believes both of those things literally.

Three things break, and none of them announce themselves clearly:

- **URLs are generated as `http://`.** Assets, canonical tags, and redirects come out
  insecure, producing mixed-content warnings and unstyled pages.
- **Redirect loops.** Combined with a proxy that re-forwards over HTTP, an HTTPS redirect
  never terminates.
- **Every visitor shares one IP address.** `$request->ip()` returns the proxy's address,
  so rate limiting, throttling, abuse controls, and access logs all silently stop
  distinguishing between visitors.

Configure trusted proxies in `bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->trustProxies(at: [
        '192.168.1.1',
        '10.0.0.0/8',
    ]);
})
```

When the proxy addresses are not known ahead of time — Cloudflare, or a cloud load
balancer that renumbers — trust all proxies, and restrict access to the origin at the
firewall instead so that only the proxy can reach it:

```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->trustProxies(at: '*');
})
```

`at: '*'` is safe only when the application is genuinely unreachable except through the
proxy. If the origin also answers on its public IP, a visitor can forge forwarding headers
and present any IP address they like.

Site Health reports trusted proxy configuration under its **Server checks**. If that check
is not green after a deploy behind a proxy, fix it here before sending traffic — see
[Site Health](site-health.md).

If your load balancer uses non-standard forwarding headers — AWS ELB, or the RFC 7239
`Forwarded` header — set the `headers` argument as well. Laravel's
[trusted proxies documentation](https://laravel.com/docs/13.x/requests#configuring-trusted-proxies)
lists the constants.

### Caching rules at the edge

Putting a CDN in front of Capell has its own contract: which responses may be cached, how
they are purged when an editor publishes, and how to prove it works. That is covered in
[Put Cloudflare in front](making-capell-fast.md#put-cloudflare-in-front).

Read it before enabling any caching rule. A rule that force-caches every HTML response
will cache authenticated and preview output, because it overrides the `private`,
`no-store`, and missing-public headers Capell uses to keep unsafe responses out of shared
caches.

## Security headers

Capell Frontend emits security headers on every public response that passes through
Laravel: `X-Content-Type-Options`, `X-Frame-Options`, `Content-Security-Policy`,
`Referrer-Policy`, `Permissions-Policy`, and `X-Permitted-Cross-Domain-Policies`. This is
on by default and can be turned off with `security.headers.enabled`.

`Strict-Transport-Security` is the exception. Capell emits it only when the host
application sets `security.headers.hsts.enabled` **and** the request is secure, so by
default it is not emitted and the web server should supply it — as in the block above.

Two consequences follow, and both are easy to get wrong in opposite directions.

**Do not emit the same header from two layers.** If you add the six middleware-owned
headers to the web server as well, responses carry each one twice. Some scanners report
duplicates as a finding, and browsers are not required to agree on which value wins.
Decide per header which layer owns it. If you enable Capell's own HSTS, remove the
`add_header Strict-Transport-Security` line from the server block.

**Responses that never reach Laravel get no headers at all.** Anything served straight
from disk or from the edge — the optional static HTML cache, generated artifacts, a
separate static docs site — bypasses the middleware entirely. Those responses need the
full set from the web server.

This interacts with an nginx rule that surprises people:

> `add_header` directives are inherited from an outer scope **only if the inner scope
> declares none of its own**. A `location` block that sets a single `add_header` silently
> discards every `add_header` inherited from `server` or `http`.

So a page-cache location that adds nothing but `Cache-Control` drops the entire
server-level security header set for exactly the responses that have no middleware to
fall back on. Any `location` that declares `add_header` must repeat the complete set it
still needs. See [Making Capell fast](making-capell-fast.md#serve-generated-html-before-php)
for the page-cache locations this applies to.

Behind a proxy, HSTS also depends on the previous section: Capell emits it only when
`$request->isSecure()`, which is false until trusted proxies are configured. A site that
enables Capell's HSTS but has not set trusted proxies emits no HSTS at all and reports no
error.

Verify with `GET`, not `HEAD`. A `HEAD` request does not necessarily traverse the same
static-cache path, so it can return a different header set from the `GET` a visitor
actually makes:

```bash
curl -sS -D - -o /dev/null https://example.com/ | grep -Ei '^(strict-transport|x-|content-security|referrer-policy|permissions-policy):'
```

Each header should appear exactly once, on both a cache `MISS` and a cache `HIT`.

## Keep the queue and scheduler running

These are two separate things and a site needs both. Neither produces an error when
missing — work simply never happens. Pages appear to publish and then do not update,
static HTML never regenerates, and marketplace installs sit forever at "queued".

### Queue worker

Unless `QUEUE_CONNECTION` is `sync`, a persistent worker process must be running.

With systemd, as `/etc/systemd/system/capell-worker.service`:

```ini
[Unit]
Description=Capell queue worker
After=network.target

[Service]
User=www-data
Restart=always
RestartSec=5
ExecStart=/usr/bin/php /var/www/example.com/current/artisan queue:work --sleep=3 --tries=3 --max-time=3600

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl enable --now capell-worker
sudo systemctl status capell-worker
```

With Supervisor, as `/etc/supervisor/conf.d/capell-worker.conf`:

```ini
[program:capell-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/example.com/current/artisan queue:work --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/log/capell-worker.log
stopwaitsecs=3600
```

```bash
sudo supervisorctl reread && sudo supervisorctl update
sudo supervisorctl status
```

Run `php artisan queue:restart` as part of every deploy so workers pick up new code.
Workers hold the old release in memory until they restart.

**Marketplace installs use their own queue.** A plain `queue:work` will not process them,
so a site with a worker can still have marketplace operations that never start. See
[Marketplace configuration](../development/configuration.md#marketplace-config) for the
connection and queue names, and [Marketplace hosting](marketplace-hosting.md) for the
host capability tiers.

More checks in [queue worker troubleshooting](troubleshooting.md#queue-worker).

### Scheduler

Laravel's scheduler is separate from the queue and needs `schedule:run` invoked every
minute. Add one crontab entry for the application user:

```cron
* * * * * cd /var/www/example.com/current && php artisan schedule:run >> /dev/null 2>&1
```

One entry is enough regardless of how many scheduled tasks are registered. Confirm it is
firing:

```bash
php artisan schedule:list
```

More checks in [scheduler troubleshooting](troubleshooting.md#scheduler).

## Verify before traffic

Run the application checks:

```bash
php artisan optimize:clear
php artisan capell:doctor
php artisan capell:upgrade --dry-run
```

Then confirm, from outside:

- a published page returns `200` on the canonical domain, over HTTPS;
- the non-canonical hostname redirects to the canonical one;
- the administrator can sign in and the Pages workspace is styled;
- Site Health has no red checks, including trusted proxies;
- the queue worker and scheduler are both running;
- database and media backups are enabled, offsite, monitored, and restorable.

### Telling the edge apart from the origin

Once a CDN is in front, a public `curl` no longer tells you what your application is
doing. A fix can be deployed and verified and still not appear publicly, because the edge
is serving a stored copy.

Check which one answered:

```bash
curl -sS -D - -o /dev/null https://example.com/ \
    | grep -Ei '^(cache-control|cf-cache-status|age|set-cookie):'
```

A `cf-cache-status: HIT` with a non-zero `age` means you are reading the edge, not the
application.

To reach the origin directly, resolve the hostname to the server yourself. The `Host`
header must stay correct, or the request lands on the wrong virtual host:

```bash
curl -k --resolve example.com:443:203.0.113.10 https://example.com/
```

Run that from the server with `127.0.0.1` when you want to bypass the network entirely.
Diagnose the application only after the origin has shown you the same problem — otherwise
you are debugging a cached copy of a bug you already fixed.

When the origin is correct and the edge is stale, purge it rather than waiting out the
TTL. The purge command belongs to the package that owns the page cache; see
[Put Cloudflare in front](making-capell-fast.md#put-cloudflare-in-front).

## Further reading

- [Web server configuration](web-server.md) — the static HTML cache and multi-node hosting
- [Making Capell fast](making-capell-fast.md) — serving tiers, CDN rules, and PHP tuning
- [Site Health](site-health.md) — the pre-traffic check list
- [Backups and restore](backups.md) — configure this before launch, not after
- [Troubleshooting](troubleshooting.md) — error strings and their fixes
