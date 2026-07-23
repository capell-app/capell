# Web server configuration

Capell runs on a standard Laravel web server configuration. This page covers the parts
that are *not* standard: serving the static HTML cache, and what changes when you run
more than one node.

## Three different things called "cache"

Confusing these is the most common source of "why is my page stale" tickets, so it is
worth being precise:

| Name | Where it lives | Who serves it |
| --- | --- | --- |
| Object cache | Your Laravel cache store | Capell, in PHP — see [page cache architecture](../architecture/page-cache.md) |
| Static HTML cache | `public/page-cache` | Your **web server**, before PHP runs |
| Static artifacts | `storage/framework/capell-static-artifacts` | Nobody — export output from `capell:generate-html` |

Only the middle one needs web-server rules. The static HTML cache is provided by the
optional `capell-app/html-cache` package; if you have not installed it, you can skip the
rest of this page.

## Serving the static HTML cache

The point of the static HTML cache is to answer anonymous requests from disk without
booting PHP. That only happens if the web server checks for a cached file *before*
falling through to `index.php`.

Cached files are written as `public/page-cache/<path>.html`.

### nginx

Add the `page-cache` lookup to your `location /` block, ahead of the normal Laravel
fallback:

```nginx
location / {
    try_files /page-cache/$uri.html $uri $uri/ /index.php?$query_string;
}
```

Never serve the cache to authenticated visitors. If your site has logged-in users,
bypass the cache when a session cookie is present:

```nginx
set $cache_bypass "";
if ($http_cookie ~* "laravel_session") {
    set $cache_bypass "no-cache";
}

location / {
    if ($cache_bypass = "no-cache") {
        rewrite ^ /index.php?$query_string last;
    }
    try_files /page-cache/$uri.html $uri $uri/ /index.php?$query_string;
}
```

### Apache

In `public/.htaccess`, before Laravel's existing front-controller rules:

```apache
<IfModule mod_rewrite.c>
    RewriteEngine On

    # Skip the cache for signed-in visitors.
    RewriteCond %{HTTP_COOKIE} !laravel_session
    RewriteCond %{REQUEST_METHOD} GET
    RewriteCond %{DOCUMENT_ROOT}/page-cache/%{REQUEST_URI}\.html -f
    RewriteRule ^(.*)$ /page-cache/$1.html [L]
</IfModule>
```

Only ever serve cached HTML for `GET`. A cached response to a `POST` is always a bug.

## Deploying

Run this on every node after new code is in place:

```bash
php artisan capell:runtime-refresh
```

It rebuilds the package, view, config, and route caches, warms critical pages, runs
Capell Doctor, and exits non-zero if any stage fails — so a broken deploy fails your
pipeline rather than your site. Under Octane, restart the workers afterwards.

## Multiple nodes

Capell was designed for single-node hosting, and several subsystems still assume it.
Read the [hosting audit](hosting-audit-2026-07.md) before scaling out. The short version:

- **Use a shared cache store.** Redis or Memcached reachable by every node. The `file`
  and `array` drivers make each node's cache — and every lock built on it — private to
  that node. This is a correctness requirement, not a performance tip.
- **The static HTML cache is node-local.** `public/page-cache` lives on each node's own
  disk, and publishing a page invalidates only the node that handled the request. Either
  put it on shared storage, or put a CDN or reverse proxy in front and purge that
  instead.
- **Run `capell:runtime-refresh` on every node.** The caches it rebuilds are per-node.
- **Build assets once, deploy them everywhere.** The admin's "rebuild frontend assets"
  action writes to the local `public/` of whichever worker ran it; other nodes will 404
  the hashed filenames.
- **Run only one upgrade at a time.** Current installs enforce this in the shared
  database through `capell_upgrade_locks`, independently of cache topology. An
  installation upgrading from a version before that table existed temporarily falls
  back to the configured cache lock until its migrations run, so the shared-cache
  requirement still applies during that first upgrade.
- **Use sticky sessions**, or a shared disk for Livewire temporary uploads. Filament
  uploads a file in one request and consumes it in a later one.

## Further reading

- [Hosting audit — July 2026](hosting-audit-2026-07.md)
- [Running Capell on Laravel Octane](octane.md)
- [Page cache architecture](../architecture/page-cache.md)
- [Install guide](../getting-started/install.md)
