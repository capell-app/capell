# Server Configuration

This document covers production server expectations for Capell Frontend, static artifact generation, and local cache settings.

---

## Overview

Capell Frontend serves public page requests through Laravel. The package can also generate static HTML artifacts with metadata for deployment pipelines, CDNs, or static export tooling:

```sh
php artisan capell:generate-html
```

By default, generated artifacts are written under `storage/framework/capell-static-artifacts`. Set `CAPELL_FRONTEND_STATIC_ARTIFACTS_PATH` when a deployment needs those artifacts written to a different writable directory.

This package no longer assumes a public page-cache directory. Do not configure Apache or Nginx to serve one unless another installed package explicitly owns and documents that directory.

The optional `capell-app/html-cache` package is such a package: it owns `public/page-cache` and documents the rules that serve it. If you have installed it, add those rules from [Serving the static HTML cache](../../../docs/operations/web-server.md#serving-the-static-html-cache). If you have not, there is no page-cache directory to serve and the instruction above stands.

---

## Web Server

Configure the web server with the normal Laravel public root and fallback behavior:

```nginx
server {
    listen 80;
    server_name example.com;
    root /var/www/html/public;

    index index.php;

    # Media uploads pass through this limit before PHP sees them.
    client_max_body_size 32m;

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

This block listens on port 80 for brevity. Production sites terminate TLS; see
[Terminate TLS](../../../docs/operations/going-live.md#terminate-tls) for the 443 block,
the port-80 redirect, and certificate renewal.

Two lines above are easy to leave out and expensive to get wrong:

- `try_files $uri =404;` inside the PHP location. Without it, a host with
  `cgi.fix_pathinfo=1` can be persuaded to hand a request such as
  `/media/photo.jpg/x.php` to PHP-FPM, executing an uploaded file. Capell accepts media
  uploads, so treat this line as required rather than defensive.
- `client_max_body_size`. nginx defaults to `1m` and rejects anything larger with a `413`
  before PHP runs, so editors see an upload fail with no application error. Set it at
  least as high as PHP's `upload_max_filesize` and `post_max_size`, and keep all three in
  step.

Enable text compression for public HTML and text assets in the same server or proxy layer. Lighthouse's `uses-text-compression` audit expects compressed responses for HTML, CSS, JavaScript, SVG, JSON, and XML:

```nginx
gzip on;
gzip_vary on;
gzip_min_length 1024;
gzip_types
    text/plain
    text/css
    text/xml
    application/javascript
    application/json
    application/xml
    application/rss+xml
    image/svg+xml;
```

If Brotli is available in the production proxy, enable it for the same MIME types. Do not audit through an uncompressed local PHP server when comparing Lighthouse scores for Capell frontend pages.

### Release-safe Vite assets

Do not serve `/build/assets/*` exclusively through a `current/public` symlink when HTML can be cached beyond a deployment. A cached page from release A can request A's content-hashed CSS after `current` points at release B; deleting or hiding A's build directory then turns a normal zero-downtime switch into a site-wide unstyled response.

The host deployment must:

1. attest every regular file under the release's `public/build/assets` directory;
2. publish those files into shared, append-only storage before switching the live release;
3. fail if an existing hashed filename has different bytes;
4. serve `/build/assets/` from that shared store with immutable cache headers;
5. atomically publish direct CSS entries under `/build/fallback/{entry}` with `no-cache` headers;
6. verify shared delivery before and after the switch, and republish the rollback candidate's stable fallback before rollback.

Retain old hashes for at least the longest HTML `max-age`, surrogate cache, stale-while-revalidate, browser history, and rollback window. A monotonic store with no automatic deletion is the safe initial policy; add garbage collection only when it can prove that no retained release or cache window still references a file.

This contract covers JavaScript chunks, CSS, fonts, and other Vite outputs—not only the stylesheet that first exposed the problem. Edge-cache purging remains useful for fresh HTML, but it is not a substitute for retaining immutable assets.

---

## Static Artifacts

Generate artifacts for all published page URLs:

```sh
php artisan capell:generate-html
```

Generate artifacts for one site or selected URLs:

```sh
php artisan capell:generate-html --site=1
php artisan capell:generate-html --url=/ --url=/about
```

The generated manifest is written to:

```text
storage/framework/capell-static-artifacts/manifest.json
```

Each manifest entry includes the output file path, response headers, dependency fingerprints, runtime fingerprints, asset fingerprints, and generation time. Deployment tooling should read the manifest instead of guessing paths from URLs.

Capell refuses to write a static artifact when the rendered response contains explicit authoring markers, field paths, model IDs, or signed admin editor URLs. Those responses are sent with `Cache-Control: private, no-store` and `X-Frontend-Cache: BYPASS`. Treat this as a deployment blocker: fix the Blade, theme, or package output rather than trying to force artifact generation.

---

## Cache Management

After bulk content changes or a database restore, clear Laravel's runtime caches and regenerate static artifacts:

```sh
php artisan optimize:clear
php artisan capell:generate-html
```

Capell automatically invalidates model-aware render data and listing caches when content changes are published. The following changes trigger targeted invalidation:

- Publishing a page invalidates that page's render data.
- Updating a page's slug invalidates the page, descendants, and listing pages.
- Updating site settings, translations, themes, or media invalidates cached render data that used those records.
- Site setting changes also match URLs by configured domains, so root pages such as the homepage are covered.
- Changes to global navigation invalidate pages that render navigation.

---

## Development Environment

To skip frontend cache reads and writes in development, add to your `.env`:

```env
DEBUG_SKIP_CACHE=true
CAPELL_HTML_CACHE=false
CAPELL_WRITE_HTML_CACHE=false
CAPELL_PUBLIC_RENDER_DATA_CACHE=false
CAPELL_MINIFY_HTML=false
```

`DEBUG_SKIP_CACHE=true` bypasses cache reads and writes on every request without disabling the cache system globally. This is the simplest option for local debugging.

---

## Performance Optimisations

- Enable HTTP/2 or HTTP/3 on the production server or proxy.
- Enable gzip or Brotli for HTML, CSS, JavaScript, SVG, JSON, and XML.
- Set long-lived cache headers for versioned CSS, JavaScript, images, and fonts.
- Use a CDN in front of Laravel or in front of the generated static artifacts.
- Use OPcache for PHP to speed up the dynamic fallback path.
- Consider a reverse-proxy cache only when it respects Capell's public safety and authenticated-rendering headers.

---

## Further Reading

- [Page & Site Loading](page-site-loading.md) - how the frontend request pipeline works
- [Testing Frontend](testing-frontend.md) - public rendering and cache-safety checks
- [Presentation Delivery](presentation-delivery.md) - renderer and layout behavior
- [Artisan Commands](../../../docs/development/artisan-commands.md) - command reference
