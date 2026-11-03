# Web server configuration

Capell runs on a standard Laravel web server configuration. This page covers the parts
that are _not_ standard: serving the static HTML cache, and what changes when you run
more than one node.

For the standard parts — DNS, TLS, the base server block, trusted proxies, and process
supervision — see [Going live](going-live.md). The base server block itself lives in
[frontend server configuration](../../packages/frontend/docs/server-config.md); the rules
below are added to it and only apply when `capell-app/html-cache` is installed.

To redirect `/index.php/<path>` requests to the clean path, see
[Canonicalising /index.php URLs](../../packages/frontend/docs/server-config.md#canonicalising-indexphp-urls).

## Three different things called "cache"

Confusing these is the most common source of "why is my page stale" tickets, so it is
worth being precise:

| Name              | Where it lives                              | Who serves it                                                                 |
| ----------------- | ------------------------------------------- | ----------------------------------------------------------------------------- |
| Object cache      | Your Laravel cache store                    | Capell, in PHP — see [page cache architecture](../architecture/page-cache.md) |
| Static HTML cache | `public/page-cache`                         | Your **web server**, before PHP runs                                          |
| Static artifacts  | `storage/framework/capell-static-artifacts` | Nobody — export output from `capell:generate-html`                            |

Only the middle one needs web-server rules. The static HTML cache is provided by the
optional `capell-app/html-cache` package; if you have not installed it, you can skip the
rest of this page.

## Serving the static HTML cache

The point of the static HTML cache is to answer anonymous requests from disk without
booting PHP. That only happens if the web server checks for a cached file _before_
falling through to `index.php`. This page is the maintained serving contract; the
[performance guide](making-capell-fast.md#serve-generated-html-before-php) and the
package's own [cache invalidation guide](https://github.com/capell-app/capell-packages/blob/main/packages/html-cache/docs/cache-invalidation.md#cache-root-layout)
describe the same layout and point back here for the web-server rules.

### Where the files are

The package writes one file per cached URL under the `page_cache` disk, which defaults
to `public/page-cache`. The path is derived from the request, not copied from it:

| Request                                        | File                                                                  |
| ---------------------------------------------- | --------------------------------------------------------------------- |
| `https://example.com/`                         | `page-cache/https.example.com/pc__index__pc.html`                     |
| `https://example.com/about`                    | `page-cache/https.example.com/about.html`                             |
| `https://example.com/about/` or `/about/index` | `page-cache/https.example.com/about.html` (same key)                  |
| `https://example.com/blog/post`                | `page-cache/https.example.com/blog/post.html`                         |
| a cached 404 for `/missing`                    | `page-cache/https.example.com/missing.404.html`                       |
| `https://example.com:8443/about`               | `page-cache/~port-8443/https.example.com/about.html`                  |
| `http://example.com/about`                     | `page-cache/http.example.com/about.html` (scheme is part of the root) |

Three consequences for the server rules:

- **One root per scheme and host.** Point the lookup at `page-cache/<scheme>.<host>`,
  never at `page-cache/$uri`. A multi-domain installation gets one `server` block, or one
  root variable, per public origin. Non-standard ports live under `~port-<n>/`.
- **The homepage is a named file.** `/` maps to `pc__index__pc.html`, so the root
  location needs its own `try_files` entry.
- **Some keys cannot be computed by the web server.** Requests with a query string are
  not cached unless the package stored an explicit pagination variant, and when
  `CAPELL_HTML_CACHE_VARY_HEADERS` is configured every filename carries a `~v<hash>`
  suffix derived from those headers. Send both cases to PHP: the package middleware
  resolves the key and still serves the file from disk, just with PHP booted.

### What must never be served from disk

Apply the same bypass list at the web server, in the package middleware, and at any
CDN in front, so a request the web server passes through is handled identically one
layer down:

- any method other than `GET` or `HEAD`;
- any query string (the cache-safe pagination variants are served by PHP);
- a Laravel session cookie, an `Authorization` header, or basic authentication;
- signed, preview, and authoring URLs;
- Livewire and other navigation or JSON requests.

Only ever serve cached HTML for `GET` and `HEAD`. A cached response to a `POST` is
always a bug.

### Expiry is enforced by PHP, not by the file

`CAPELL_HTML_CACHE_FILESYSTEM_TTL_SECONDS` (default 3600) bounds how long a file is
trusted, but the check runs when PHP reads the file: the package middleware ignores and
deletes a file older than the TTL. A web server that serves the file directly never
runs that check, so a direct hit can serve a file past its TTL until invalidation
removes it or a PHP read replaces it. Content changes, withdrawals and domain changes
invalidate files immediately, so for ordinary editorial sites this is fine. Where
expiry has to be exact — scheduled publication windows, time-limited offers, legally
dated notices — keep that part of the site on PHP serving (no `try_files` rule for
those paths) and let the middleware apply the TTL.

### nginx

Add the `page-cache` lookup ahead of the normal Laravel fallback. The root variable is
per public origin:

```nginx
set $page_cache_root /var/www/capell/public/page-cache/https.example.com;
set $page_cache_bypass "";

if ($request_method !~ ^(GET|HEAD)$) {
    set $page_cache_bypass "1";
}
if ($http_cookie ~* "laravel_session") {
    set $page_cache_bypass "1";
}
if ($http_authorization != "") {
    set $page_cache_bypass "1";
}
if ($args != "") {
    set $page_cache_bypass "1";
}

location = / {
    if ($page_cache_bypass = "1") {
        rewrite ^ /index.php?$query_string last;
    }
    try_files $page_cache_root/pc__index__pc.html /index.php?$query_string;
}

location / {
    if ($page_cache_bypass = "1") {
        rewrite ^ /index.php?$query_string last;
    }
    try_files $uri $uri/ $page_cache_root$uri.html /index.php?$query_string;
}
```

If you add `add_header` directives to these locations (for example a public
`Cache-Control` policy, see the [performance guide](making-capell-fast.md#serve-generated-html-before-php)),
repeat the full security-header set inside them: responses served from disk never reach
Laravel's middleware, and an `add_header` in a location replaces every header inherited
from the `server` block. See [Security headers](going-live.md#security-headers).

### Apache

In `public/.htaccess`, before Laravel's existing front-controller rules:

```apache
<IfModule mod_rewrite.c>
    RewriteEngine On

    # Serve the static cache only to anonymous GET/HEAD requests without a query string.
    RewriteCond %{REQUEST_METHOD} ^(GET|HEAD)$
    RewriteCond %{QUERY_STRING} ^$
    RewriteCond %{HTTP_COOKIE} !laravel_session
    RewriteCond %{HTTP:Authorization} ^$

    # Homepage: the cache stores it as a named file under the scheme.host root.
    RewriteCond %{DOCUMENT_ROOT}/page-cache/%{REQUEST_SCHEME}.%{HTTP_HOST}/pc__index__pc.html -f
    RewriteRule ^/?$ /page-cache/%{REQUEST_SCHEME}.%{HTTP_HOST}/pc__index__pc.html [L]

    RewriteCond %{REQUEST_METHOD} ^(GET|HEAD)$
    RewriteCond %{QUERY_STRING} ^$
    RewriteCond %{HTTP_COOKIE} !laravel_session
    RewriteCond %{HTTP:Authorization} ^$
    RewriteCond %{DOCUMENT_ROOT}/page-cache/%{REQUEST_SCHEME}.%{HTTP_HOST}/$1.html -f
    RewriteRule ^(.+?)/?$ /page-cache/%{REQUEST_SCHEME}.%{HTTP_HOST}/$1.html [L]
</IfModule>
```

`%{HTTP_HOST}` includes a non-standard port as `host:port`; those installations use the
`~port-<n>/` layout above and need a server-specific rule, or PHP serving.

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
Before scaling out, account for these operating constraints:

- **Use a shared cache store.** Redis or Memcached reachable by every node. The `file`
  and `array` drivers make each node's cache — and every lock built on it — private to
  that node. This is a correctness requirement, not a performance tip. The web installer
  refuses to start when `CAPELL_MULTI_NODE=true` and its progress state would use a
  node-local cache store.
- **The static HTML cache must be on shared storage.** `public/page-cache` lives on each
  node's own disk by default, and publishing a page invalidates only the node that
  handled the request. Mount one `page_cache` directory on every web and queue node and
  acknowledge it with `CAPELL_HTML_CACHE_SHARED_PAGE_CACHE=true`. A CDN purge driver
  empties the edge in addition to that; it does not replace shared storage, because the
  next edge miss would be answered from another node's stale copy.
- **Run `capell:runtime-refresh` on every node.** The caches it rebuilds are per-node.
- **Build assets once, deploy them everywhere.** The admin's "rebuild frontend assets"
  action writes to the local `public/` of whichever worker ran it; other nodes will 404
  the hashed filenames.
- **Run only one upgrade at a time.** Current installs enforce this in the shared
  database through `capell_upgrade_locks`, independently of cache topology. An
  installation upgrading from a version before that table existed temporarily falls
  back to the configured cache lock until its migrations run, so the shared-cache
  requirement still applies during that first upgrade.
- **Use a shared disk for Livewire temporary uploads.** Filament uploads a file in one
  request and consumes it in a later one. When `CAPELL_MULTI_NODE=true`, Capell rejects
  Livewire uploads backed by a node-local filesystem even if the load balancer normally
  provides session affinity; the storage topology remains correct during failover.

## Further reading

- [Going live](going-live.md)
- [Running Capell on Laravel Octane](octane.md)
- [Page cache architecture](../architecture/page-cache.md)
- [Install guide](../getting-started/install.md)
