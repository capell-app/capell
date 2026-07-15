# Performance & Caching

Use this section if you tune caching, response delivery, assets, or hydration on an installed site.

| I need to...                             | Read                                                                           |
| ---------------------------------------- | ------------------------------------------------------------------------------ |
| Tune the static HTML page cache          | [Page cache architecture](../architecture/page-cache.md)                       |
| Understand URL-to-model dependencies     | [Model URL cache](model-url-cache.md)                                          |
| Return 304 responses for unchanged pages | [ETags and conditional responses](etag-and-conditional-responses.md)           |
| Cache expensive Blade partials           | [Fragment caching](fragment-caching.md)                                        |
| Tune render-blocking CSS and preloads    | [Frontend asset optimization](critical-asset-optimization.md)                  |
| Flush caches when models change          | [Cache invalidation](cache-invalidation.md)                                    |
| Avoid eager loads on cold cache hits     | [Lazy page hydration](lazy-page-hydration.md)                                  |
| Reduce rendered HTML size                | [Frontend server configuration](../../packages/frontend/docs/server-config.md) |

Frontend Authoring uses the model URL cache to find every cached URL touched by an edited record. The editor itself is never baked into cached HTML; admin-only edit controls are added later by the beacon.

See also: [`packages/core/docs/cache.md`](../../packages/core/docs/cache.md), [`packages/core/docs/extending-capell.md`](../../packages/core/docs/extending-capell.md).
