# Static Site Extensions

> **Who is this for?**
> Developers adding custom output files (RSS feeds, alternate sitemaps, custom JSON exports) to the static-site export, or transforming the export process itself.

> **TL;DR:** The HTML Cache package (`capell-app/html-cache`) owns static-site export. Resolve its `StaticSiteExtensionRegistry` from the container and register a callable. Your handler receives the Site and SiteDomain being exported, plus a callback to mark URLs as processed.

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

The registry belongs to the HTML Cache package, so it only exists where that package is installed. The package binds `Capell\HtmlCache\Support\StaticSite\StaticSiteExtensionRegistry` as a singleton. Resolve it from the container inside an installed-package gate, and declare `capell-app/html-cache` in `dependencies.requires` of your `capell.json` so Capell installs it first. Register handlers early, in the `bootInstalledRuntime()` hook of your provider, before the static-site generation job runs.

When `StaticSiteGenerator` runs, it iterates over the registered handlers for each site and domain:

1. **Invocation**: Each handler callable is invoked with `($site, $siteDomain, $checkpoint)`.
2. **Progress**: Your handler calls the checkpoint callback for each URL it processes, updating progress.
3. **Totals**: The count of items your handler processes is added to the total URL count for accurate progress reporting.

Without the HTML Cache package there is no registry, nothing calls your handler, and your extension must not fail: guard the registration so it is skipped when the package is absent.

## Public API

| Method                                           | Returns                   | Purpose                                                                |
| ------------------------------------------------ | ------------------------- | ---------------------------------------------------------------------- |
| `register(string $key, callable $handler): void` | void                      | Registers a handler; a second registration of a key replaces the first |
| `has(string $key): bool`                         | bool                      | Checks if a key is registered                                          |
| `all(): array`                                   | `array<string, callable>` | Returns all registered handlers                                        |
| `clear(): void`                                  | void                      | Removes every handler (tests only)                                     |

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
use Capell\HtmlCache\Support\StaticSite\StaticSiteExtensionRegistry;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! class_exists(StaticSiteExtensionRegistry::class)) {
            return;
        }

        resolve(StaticSiteExtensionRegistry::class)->register('feed.xml', function (
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

- **Rejected visits throw**: A visitor accepts absolute HTTP(S) URLs or paths relative to the site domain base, including its path prefix. Anything else throws.
- **Key naming**: Choose keys that don't collide with built-in exports (e.g., avoid keys that resolve to the same filename as generated page URLs). Consider prefixing with `custom:` or your app name.
- **Synchronous execution**: Handlers run in the main request/job context. Long-running operations block the entire export job. For expensive operations, consider dispatching to a separate queue or deferring to a post-export step.
- **Directory/file handling**: The registry does not manage filesystem operations. Your handler must ensure the export directory exists and has write permissions. Consider using Laravel's `Storage` facade or explicitly creating directories.
- **Checkpoint is required for progress tracking**: If you generate multiple files or URLs, call `$checkpoint()` for each one so that job progress and totals are accurate. A single call to `$checkpoint()` counts as one processed item.
- **No return value**: Handlers must return `void`. The registry does not capture or use return values.

## Related guides

- [Extending Capell](extending-capell.md) — Overview of extension patterns and extenders
- [Content Management](content-management.md) — Working with pages and content types
- [Multi-site & Multi-lingual](multi-site-multi-lingual.md) — Understanding Site and SiteDomain models
