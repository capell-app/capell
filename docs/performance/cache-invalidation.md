# Cache Invalidation

![Capell Cache Invalidation screenshot](../images/generated/admin/site-health-page.png)

> **Who is this for?**
> Developers adding custom models that should invalidate frontend caches when changed (e.g., custom news articles, product data, or settings).

> **TL;DR:**
> Register your custom model with `CacheInvalidationRegistry::registerDependency()` to declare "when this model changes, flush these cache keys." Resolve the registry from the container; it is not a static utility.

---

## When to use this

Cache invalidation via `CacheInvalidationRegistry` is for registering relationships between **custom models and cache keys**. Capell ships with built-in registrations for `Site`, `Language`, `Page`, `Navigation`, and `SiteDomain`, plus special-case handling for `Translation` records and site-logo `Media` (a logo change flushes the frontend tag). If you:

- Add a custom model (e.g., `BlogPost`, `TeamMember`)
- Want frontend caches to bust automatically when that model is saved/deleted
- Don't want to manually call `Cache::forget()` in observers

...then `CacheInvalidationRegistry` is your tool.

This is **not** a replacement for [fragment caching](fragment-caching.md). Fragment caching uses surrogate keys inside Blade templates; this registry uses model lifecycle events to decide which cache keys to flush. They compose: a model observer calls the registry to flush keys, then flushes fragments tagged with surrogate keys (if any).

## How It's Wired

The registry is a container-managed service. Register dependencies during service-provider boot, then call `invalidateForModel()` from observers or listeners that handle model changes. Observers in `packages/core/src/Observers/` (e.g., `SiteObserver`, `PageObserver`, `LanguageObserver`) trigger events (`PageSaved`, `PageDeleted`) that are consumed by listeners in `packages/frontend/src/Listeners/`.

**Current flow (built-in models):**

1. Model observer (e.g., `PageObserver::saved()`) calls `CapellCoreHelper::flushCache()` with enum-based keys, **or** fires an event like `PageSaved`
2. Frontend listeners like `PurgeCdnCacheOnPageChangeListener` handle the event and call custom logic (e.g., `PurgeCdnCacheByPageAction`)
3. For custom models, wire the observer to call `resolve(CacheInvalidationRegistry::class)->invalidateForModel(ModelClass::class)`

**File locations:**

- Registry: `packages/frontend/src/Support/Cache/CacheInvalidationRegistry.php`
- Built-in registrations: inside the registry's `$modelDependencies` property

## Public API

| Method               | Signature                                                                    | Purpose                                                                                                                                                       |
| -------------------- | ---------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `registerDependency` | `registerDependency(string $modelClass, string\|array $cachePatterns): void` | Register one or more cache patterns for a model class. Patterns are merged with any existing registrations for that class. Pass a string or array of strings. |
| `invalidateForModel` | `invalidateForModel(string $modelClass): void`                               | Look up patterns for a model class and flush the appropriate cache keys. Called from model observers.                                                         |

## What "patterns" means

Patterns are cache **keys or tags**, passed to Laravel's cache driver:

- **Exact-key patterns** (no `*` wildcard): forwarded to `Cache::forget($pattern)`, matching exact cache keys
    - Example: `'sites'`, `'languages'`, `'page-error-404'`

- **Wildcard patterns** (contain `*`): trigger a **full tag flush** instead
    - Example: `'page-*'`, `'site-related-*'`
    - When any wildcard pattern is detected, the registry calls `Cache::tags(['capell-frontend'])->flush()` and returns early
    - This is a safety mechanism: rather than implementing wildcard matching across cache backends, the registry opts for full flush

**Why the conservative approach?** Different cache drivers (Redis, Memcached, file) handle wildcards differently. A full tag flush is predictable and avoids stale data.

## Example — registering a custom model

Create a custom model observer for your `BlogPost` model:

```php
<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\BlogPost;
use Capell\Frontend\Support\Cache\CacheInvalidationRegistry;

final class BlogPostObserver
{
    public function saved(BlogPost $blogPost): void
    {
        resolve(CacheInvalidationRegistry::class)->invalidateForModel(BlogPost::class);
    }

    public function deleted(BlogPost $blogPost): void
    {
        resolve(CacheInvalidationRegistry::class)->invalidateForModel(BlogPost::class);
    }
}
```

Register the dependency in your app's service provider:

```php
<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\BlogPost;
use Capell\Frontend\Support\Cache\CacheInvalidationRegistry;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function boot(CacheInvalidationRegistry $cacheInvalidation): void
    {
        $cacheInvalidation->registerDependency(
            modelClass: BlogPost::class,
            cachePatterns: ['blog-posts', 'blog-posts:*', 'homepage-featured'],
        );
    }
}
```

Now, when a `BlogPost` is saved or deleted, the observer calls `invalidateForModel()`, which flushes `'blog-posts'` and `'homepage-featured'` keys exactly, and (because `'blog-posts:*'` contains `*`) triggers a full `Cache::tags('capell-frontend')->flush()`.

## Companion APIs

**Fragment caching vs. invalidation registry:**

- **`FragmentCache::invalidateBySurrogateKey(string $surrogateKey)`** — Used **inside Blade templates** to tag fragments. Called manually from event listeners when you want surgical invalidation of specific fragments.
    - Example: `@cache('featured-posts', 3600, ['featured-posts:list'])`; later, `FragmentCache::invalidateBySurrogateKey('featured-posts:list')` clears just that fragment.

- **`CacheInvalidationRegistry::registerDependency()` + `invalidateForModel()`** — Used for **model-driven invalidation**. Declares "when MODEL X changes, flush THESE cache keys." Typically called from model observers, not directly in templates.

The registry is **declaration-time** (in service providers); fragment invalidation is **event-time** (in observers/listeners). Use both:

```php
// Service provider: declare the registry
$registry = resolve(CacheInvalidationRegistry::class);

$registry->registerDependency(
    BlogPost::class,
    ['blog-posts', 'blog-featured-*'],
);

// Observer: invalidate both registry keys and fragment surrogates
public function saved(BlogPost $blogPost): void {
    resolve(CacheInvalidationRegistry::class)->invalidateForModel(BlogPost::class);
    FragmentCache::invalidateBySurrogateKey('blog-featured');
}
```

## Gotchas

- **Register in `boot()`, not `register()`** — service providers should register dependencies in `boot()` rather than `register()`, ensuring other packages have already initialized. Many CMS extensions register their own cache patterns and depend on a predictable boot order.

- **Model class must be fully qualified** — pass the full namespace: `BlogPost::class` or `'App\Models\BlogPost'`, not just `'BlogPost'`.

- **Multiple registrations merge** — calling `registerDependency()` twice for the same model class merges the pattern arrays:

    ```php
    $registry = resolve(CacheInvalidationRegistry::class);

    $registry->registerDependency(Post::class, ['posts']);
    $registry->registerDependency(Post::class, ['featured']);  // Now Post maps to ['posts', 'featured']
    ```

- **Wildcard triggers full flush** — if even one pattern contains `*`, the entire `capell-frontend` tag is flushed. Wildcard patterns are an all-or-nothing signal. Use sparingly if your app has many cache keys.

- **Observer must be wired** — the registry only works if your model observer is registered. Register it in a service provider:

    ```php
    BlogPost::observe(BlogPostObserver::class);
    ```

- **No automatic wiring** — unlike Capell's built-in models (registered in core observers), custom models are **not** automatically observed. You must wire the observer yourself.

## Related

- [Fragment caching](fragment-caching.md) — cache expensive Blade partials with surrogate key invalidation.
- [ETag and conditional responses](etag-and-conditional-responses.md) — validate client caches without recomputing.
- [Critical asset optimization](critical-asset-optimization.md) — defer non-critical CSS and JS for faster first paint.
- [Performance index](README.md)
