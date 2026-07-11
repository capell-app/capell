# Static Site Extensions

![Capell Static Site Extensions screenshot](./images/screenshots/core-page-structure.png)

> **Who is this for?**
> Developers adding custom output files (RSS feeds, alternate sitemaps, custom JSON exports) to the static-site export, or transforming the export process itself.

> **TL;DR:** Register a callable with `StaticSiteExtensionRegistry` to inject custom handlers into the static-site generation pipeline. Your handler receives the Site and SiteDomain being exported, plus a callback to mark URLs as processed.

---

## When to use this

You have custom content that needs to be generated and deployed as part of the static-site export—for example:

- An RSS feed for blog posts
- An alternate sitemap format (JSON, XML variants)
- Custom JSON metadata for client-side consumption
- Transformed or filtered versions of existing pages

Using the registry is preferred over post-export shell scripts because:

- Your custom handler integrates into the progress tracking (checkpoint callback counts your output correctly)
- The generated files are included in the export job tracking
- You have direct access to the database models (Site, SiteDomain) without extra queries

## How it's wired

The registry is a **singleton** accessed via `StaticSiteExtensionRegistry::instance()`. Handlers are registered early (typically in a service provider's `boot()` method) before the static-site generation job runs.

When `StaticSiteGenerator->process()` executes (invoked by the `StaticSiteExportAction` or related pipeline):

1. **Prepare phase**: `StaticSiteGenerator::processExtensionHandlers()` iterates over all registered handlers.
2. **Invocation**: Each handler callable is invoked with `($site, $siteDomain, $checkpoint)`.
3. **Progress**: Your handler calls the checkpoint callback for each URL/file it processes, updating progress.
4. **Totals**: The count of items your handler processes is added to the total URL count for accurate progress reporting.

**File references:**

- Registry definition: `packages/core/src/Support/StaticSite/StaticSiteExtensionRegistry.php`
- Consumer/invocation: `packages/core/src/Support/StaticSite/StaticSiteGenerator.php`, lines 74–88 (the `processExtensionHandlers()` method)

## Public API

| Method                                             | Returns                       | Purpose                                           |
| -------------------------------------------------- | ----------------------------- | ------------------------------------------------- |
| `instance(): self`                                 | `StaticSiteExtensionRegistry` | Returns the singleton instance                    |
| `register(string $key, callable $extension): void` | void                          | Registers a handler (no-op if key already exists) |
| `has(string $key): bool`                           | bool                          | Checks if a key is registered                     |
| `all(): array`                                     | `array<string, callable>`     | Returns all registered handlers                   |
| `reset(): void`                                    | void                          | Clears all handlers (testing only)                |

## Hook contract

Handlers are callables with signature:

```php
callable(Site $site, SiteDomain $siteDomain, Closure $checkpoint): void
```

Where:

- **`$site`**: The Site model being exported (access properties like `id`, `slug`, `domain`, etc.)
- **`$siteDomain`**: The SiteDomain (language/domain pair) being exported
- **`$checkpoint`**: A closure `fn (string $url): void` that you call for each URL/file your handler processes. Increments the job progress counter and invokes any registered progress callback.

**Important:** The checkpoint callback accepts only a URL/file path as a string (for progress tracking); it does not return a value.

## Example — emitting a custom feed

```php
<?php

declare(strict_types=1);

namespace App\Providers;

use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Support\StaticSite\StaticSiteExtensionRegistry;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        StaticSiteExtensionRegistry::instance()->register('feed.xml', function (
            Site $site,
            SiteDomain $siteDomain,
            \Closure $checkpoint,
        ): void {
            // Fetch posts for this site/language
            $posts = $site->pages()
                ->where('type_slug', 'post')
                ->where('language_id', $siteDomain->language_id)
                ->get();

            // Build XML feed
            $feedXml = '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
            $feedXml .= '<rss version="2.0">' . PHP_EOL;
            $feedXml .= '<channel>' . PHP_EOL;

            foreach ($posts as $post) {
                $feedXml .= sprintf(
                    '  <item><title>%s</title><link>%s</link></item>' . PHP_EOL,
                    htmlspecialchars($post->title, ENT_XML1),
                    $post->url,
                );
            }

            $feedXml .= '</channel>' . PHP_EOL;
            $feedXml .= '</rss>';

            // Write to export directory or storage
            $path = storage_path("exports/{$site->slug}/feed.xml");
            file_put_contents($path, $feedXml);

            // Mark as processed
            $checkpoint('feed.xml');
        });
    }
}
```

## Gotchas

- **Key naming**: Choose keys that don't collide with built-in exports (e.g., avoid keys that resolve to the same filename as generated page URLs). Consider prefixing with `custom:` or your app name.
- **Synchronous execution**: Handlers run in the main request/job context. Long-running operations block the entire export job. For expensive operations, consider dispatching to a separate queue or deferring to a post-export step.
- **Directory/file handling**: The registry does not manage filesystem operations. Your handler must ensure the export directory exists and has write permissions. Consider using Laravel's `Storage` facade or explicitly creating directories.
- **Checkpoint is required for progress tracking**: If you generate multiple files or URLs, call `$checkpoint()` for each one so that job progress and totals are accurate. A single call to `$checkpoint()` counts as one processed item.
- **No return value**: Handlers must return `void`. The registry does not capture or use return values.

## Related

- [Extending Capell](extending-capell.md) — Overview of extension patterns and extenders
- [Content Management](content-management.md) — Working with pages and content types
- [Multi-site & Multi-lingual](multi-site-multi-lingual.md) — Understanding Site and SiteDomain models
