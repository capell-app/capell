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

| Method | Returns | Purpose |
|--------|---------|---------|
| `remember(string $key, callable $callback, int $ttlSeconds = 3600, array $surrogateKeys = [])` | `mixed` | Cache the output of `$callback` under `$key` for `$ttlSeconds`, optionally tagging it with surrogate keys for bulk invalidation. |
| `invalidateBySurrogateKey(string $surrogateKey)` | `void` | Immediately invalidate all fragments tagged with this surrogate key. |
| `flush()` | `void` | Flush all fragment cache. |

## Example

**Blade usage** — cache a product grid for 10 minutes, tagged with the category's surrogate key:

```blade
@cache('product-grid:' . $category->id, 600, ['category:' . $category->id])
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
- **Directive supports nesting** — `@cache` blocks can be nested; the directive uses an internal stack to manage `@endcache` pairing.
- **Default TTL is 1 hour** — if you omit the second argument, fragments cache for 3600 seconds.
- **Surrogate map persists for 30 days** — the internal mapping of surrogate keys to fragment cache keys has its own TTL; old surrogate relationships expire after 30 days.

## Related

- [Cache invalidation](cache-invalidation.md) — strategies for invalidating caches across your application.
