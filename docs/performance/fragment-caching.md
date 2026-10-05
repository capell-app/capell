# Fragment Caching

> **Who is this for?**
> Developers who want to cache expensive Blade partials (navigation menus, sidebars, computed product lists) that don't change as often as the page itself.

> **TL;DR:**
> Fragment caching stores the HTML output of expensive Blade blocks and reuses it across multiple page renders, with invalidation via surrogate keys.

---

## When to use this

Fragment caching is perfect for expensive Blade partials that rarely change: navigation menus, sidebars, category product lists, or computed component output. It trades a small memory footprint for significant rendering speed.

Unlike full-page caching (which caches the entire HTML response), fragment caching lives inside your Blade templates and lets you mix static fragments with dynamic content on the same page. When you need to invalidate a fragment (e.g., after updating a category), use surrogate keys instead of guessing cache key names.

## Public API

| Method                                                                                         | Returns | Purpose                                                                                                                          |
| ---------------------------------------------------------------------------------------------- | ------- | -------------------------------------------------------------------------------------------------------------------------------- |
| `remember(string $key, callable $callback, int $ttlSeconds = 3600, array $surrogateKeys = [])` | `mixed` | Cache the output of `$callback` under `$key` for `$ttlSeconds`, optionally tagging it with surrogate keys for bulk invalidation. |
| `invalidateBySurrogateKey(string $surrogateKey)`                                               | `void`  | Immediately invalidate all fragments tagged with this surrogate key.                                                             |
| `flush()`                                                                                      | `void`  | Flush all fragment cache.                                                                                                        |

## Example

**Blade usage** — cache a product grid for 10 minutes, tagged with the category's surrogate key:

```blade
@cache ('product-grid:' . $category->id, 600, ['category:' . $category->id])
    @foreach ($category->products as $product)
        <x-product-card :product="$product" />
    @endforeach
@endcache
```

**PHP invalidation** — in a model observer, invalidate the fragment when a category is updated:

```php
<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Category;
use Capell\Frontend\Support\Cache\FragmentCache;

final class CategoryObserver
{
    public function __construct(private FragmentCache $cache)
    {
    }

    public function saved(Category $category): void
    {
        $this->cache->invalidateBySurrogateKey('category:' . $category->id);
    }
}
```

## Gotchas

- **Cache key must be deterministic** — if your key contains a loop counter or request ID, each render creates a new cache entry. Use stable identifiers (model IDs, slugs).
- **Surrogate keys are OR logic** — any matching surrogate key invalidates the fragment. If you tag a fragment with `['post:123', 'author:456']`, invalidating either key clears it.
- **Numeric surrogate keys work too** — string keys such as `'42'` and `'0'` retain their associations when the cache stores them as integer array keys.
- **Directive supports nesting** — `@cache` blocks can be nested; the directive uses an internal stack to manage `@endcache` pairing.
- **Default TTL is 1 hour** — if you omit the second argument, fragments cache for 3600 seconds.
- **Fragment metadata expires after 30 days** — namespaces and surrogate maps have bounded lifetimes. Namespace expiry safely regenerates fragments, including entries with a longer requested TTL.
- **Flush is fragment-scoped** — rotating a fragment namespace works with tagging and non-tagging stores and preserves unrelated application keys. Previously cached values expire at their original TTL; the old surrogate index is removed immediately. Invalidation also removes the fragment from every surrogate association.

## Origin identity and invalidation

Core cache keys and fragments use `Capell\Core\Support\Cache\CacheOrigin::discriminator()`.
It casts the effective request port to an integer. An absent port, 80, or 443
adds no discriminator, regardless of the perceived scheme, retaining the literal
legacy key. Every other port adds the effective scheme and integer port. Request
accessors honour trusted proxy configuration; without a request, both caches use
`app.url`, including in CLI and queue contexts. Companion HTML-cache identity
must implement this identical rule.

Non-standard fragment values use a separate `origin-value` prefix and a SHA-256
hash of the logical key and discriminator together. Standard values retain the
`value` prefix and unchanged logical key; arbitrary caller keys cannot enter the
non-standard prefix.

Bulk invalidation is intentionally origin-wide. Core tag flushes, fallback
namespace generations and pattern generations invalidate matching entries on all
ports. Fragment namespace rotation and surrogate purges also cover every port.
This safe over-invalidation avoids stale content; exact Core key removal remains
origin-specific. Shared-store entries outside the affected tag, namespace or
pattern are preserved.

Surrogate membership still uses a non-atomic read/modify/write map. The injected
`Illuminate\Contracts\Cache\Repository` and its `Store` guarantee neither an
atomic map update nor locks (locking is a separate optional `LockProvider`
contract). Interleaved writes for two port variants can lose one membership;
a subsequent surrogate purge can leave that value until TTL expiry or namespace
rotation. A deterministic characterisation test pins this pre-existing limitation.
Fixing it for every supported repository requires a separate coordination design;
this identity change does not provide that guarantee.

## Public deferred fragments

Public deferred-fragment responses use a separate owner-aware protocol. Their encrypted envelope carries the page, site, language, layout context, owner, protocol version, and a deterministic content version. The serving endpoint must re-resolve that context from the database on every request.

Authorization and version checks always precede caching:

1. Decode with `PublicFragmentReferenceCodec`.
2. Require the endpoint's exact `owner`.
3. Run `ResolvePublicFragmentContextAction` and the owner's additional checks.
4. Render and run the public HTML safety inspection.
5. Add public cache headers only to the successful response.

Any malformed, unsupported, unknown-owner, stale, unpublished, deleted, inaccessible, cross-site, cross-language, or mismatched-layout reference returns the same uncached 404. A content or publication change therefore revokes already-issued URLs immediately; a newly rendered page emits a replacement reference.

## Related

- [Cache invalidation](cache-invalidation.md) — strategies for invalidating caches across your application.
