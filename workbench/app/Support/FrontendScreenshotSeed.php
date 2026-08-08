<?php

declare(strict_types=1);

namespace Workbench\App\Support;

use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Support\Cache\CapellCacheManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

final class FrontendScreenshotSeed
{
    public static function initialize(): void
    {
        DB::transaction(static function (): void {
            $page = Page::query()
                ->whereHas('blueprint', static fn (Builder $query): Builder => $query->where('key', 'home'))
                ->with(['layout', 'site'])
                ->first();

            throw_if(! $page instanceof Page, ModelNotFoundException::class, 'The screenshot app must be seeded before building the frontend screenshot page.');

            $layout = $page->layout;
            throw_if(! $layout instanceof Layout, ModelNotFoundException::class, 'The screenshot homepage has no layout.');

            $layout->forceFill([
                'containers' => [
                    'main' => [
                        'elements' => [
                            ['element_key' => 'page-content', 'occurrence' => 1],
                        ],
                    ],
                ],
            ])->save();

            $page->translations()->updateOrCreate(
                ['language_id' => $page->site->language_id],
                [
                    'title' => 'Welcome to Capell',
                    'content' => '<p>Build and publish a clear, durable site with Capell.</p><p>This is the ordinary published homepage rendered by the local application.</p>',
                    'meta' => ['slug' => '/'],
                ],
            );

            resolve(CapellCacheManager::class)->flushCache();
        });
    }
}
