<?php

declare(strict_types=1);

namespace Workbench\App\Support;

use Capell\Admin\Filament\Resources\Pages\PageResource;
use Capell\Core\Enums\ContentStructure;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class PageBuildingBlocksFixture
{
    private const string PageName = 'Page building Blocks screenshot fixture';

    public static function editUrl(): string
    {
        return PageResource::getUrl('edit', ['record' => self::page()]);
    }

    public static function page(): Page
    {
        $page = Page::query()->firstOrNew(['name' => self::PageName]);

        if (! $page->exists) {
            $site = Site::query()->first();
            $layout = $site instanceof Site
                ? $site->layouts()->first() ?? Layout::query()->first()
                : null;
            $blueprint = Blueprint::query()->pageType()->first();

            if (! ($site instanceof Site) || ! ($layout instanceof Layout) || ! ($blueprint instanceof Blueprint)) {
                throw new ModelNotFoundException('The screenshot app must be seeded before building the Blocks editor fixture.');
            }

            $page->fill([
                'site_id' => $site->getKey(),
                'layout_id' => $layout->getKey(),
                'blueprint_id' => $blueprint->getKey(),
                'content_structure_override' => ContentStructure::Blocks->value,
                'visible_from' => now(),
            ])->save();
        }

        if ($page->getAttribute('content_structure_override') !== ContentStructure::Blocks->value) {
            $page->forceFill([
                'content_structure_override' => ContentStructure::Blocks->value,
            ])->save();
        }

        $page->translations()->updateOrCreate(
            ['language_id' => $page->site->language_id],
            [
                'title' => 'Build with typed, reorderable blocks',
                'content' => [
                    [
                        'type' => 'content',
                        'data' => [
                            'content' => '<p>This fixture uses Capell’s real Blocks page-body editor.</p>',
                        ],
                    ],
                    [
                        'type' => 'content',
                        'data' => [
                            'content' => '<p>Editors can add, configure, clone, and reorder typed page-body blocks.</p>',
                        ],
                    ],
                ],
                'meta' => ['slug' => 'page-building-blocks-fixture'],
            ],
        );

        return $page->refresh();
    }
}
