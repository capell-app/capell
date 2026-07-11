# ETag & Conditional Responses

![Capell ETag & Conditional Responses screenshot](../images/generated/admin/site-health-page.png)

> **Who is this for?**
> Developers building themes or caching strategies who want HTTP-level cache hits without rebuilding the full page cache layer.

> **TL;DR:** The ETag middleware adds weak ETags to HTML and JSON responses, allowing browsers and HTTP clients to request unchanged pages with a 304 Not Modified response instead of re-downloading the body.

---

## When to use this

Automatic 304 responses cut bandwidth on repeat visits by skipping response bodies entirely. The ETag middleware works alongside—not instead of—fragment caching and page caching. Use it when you want client-side HTTP cache validation without instrumenting the page cache layer.

## How it's wired

The ETag middleware is registered as a route alias `frontend.etag` in `packages/frontend/src/Providers/FrontendServiceProvider.php` (line 280):

```php
Route::aliasMiddleware('frontend.etag', ETagMiddleware::class);
```

It is **not** automatically applied to all requests—routes must explicitly assign the middleware via route attributes or middleware groups. Apply it to routes where you want conditional response support.

## Behavior

The middleware (`packages/frontend/src/Http/Middleware/ETagMiddleware.php`) works as follows:

- **Computes a weak ETag** from the response body using xxHash128 (64-bit truncated to 16 hex characters) and prefixes it with `W/` to indicate a weak validator.
- **Compares to `If-None-Match`** header sent by the client:
  - If the header value matches the computed ETag exactly, the middleware returns a **304 Not Modified** response with an empty body, preserving the original `Cache-Control` and `Vary` headers.
  - If no match or no header, the response passes through with the `ETag` header set.
- **Sets `Last-Modified`** header if not already present on the response, using the current request time in RFC 2822 GMT format.
- **Skips ETag generation** for:
  - Non-200 and non-404 status codes.
  - Responses without a `Content-Type` header.
  - Response content types other than `text/html` or `application/json` (checked via string position matching).

## Enabling on a route

The ETag middleware is **opt-in per route**. Apply the `frontend.etag` alias to any route that should support conditional responses:

```php
Route::get('/products', ProductsController::class)->middleware('frontend.etag');
```

All responses matching the skip conditions above (200/404 status with HTML or JSON content type) will receive ETag headers and conditional response handling.

**Gotcha:** If a response varies on something the middleware cannot see (e.g. nonce-laden CSP headers, or cache-busting query strings added by JavaScript), it will produce false 304 hits—the client will reuse stale content. Use [fragment caching](fragment-caching.md) for partials that must vary independently, and ensure cache-busting tokens are baked into the initial response body.

## Gotchas

- **Content-dependent hash**: The ETag is computed from the final response body. Any dynamic content that changes per request (CSRF tokens, timestamps, cache-busting nonces) will change the ETag and prevent 304 matches. Use [fragment caching](fragment-caching.md) for dynamic partials within otherwise-static pages.
- **Encoding-sensitive**: The hash is computed from the raw response content. If response encoding (gzip vs. identity) or character encoding changes, the ETag will differ and defeat caching.
- **HEAD requests**: HEAD requests will also receive the `ETag` and `Last-Modified` headers, allowing clients to validate without downloading the body.
- **Cache-Control interaction**: The middleware does not set `Cache-Control` on the outbound response—it preserves any existing value or defaults to `public` only when returning a 304.

## Related

- [Fragment caching](fragment-caching.md)
- [Cache invalidation](cache-invalidation.md)
