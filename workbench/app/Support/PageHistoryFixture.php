<?php

declare(strict_types=1);

namespace Workbench\App\Support;

use Capell\Admin\Filament\Resources\Pages\PageResource;
use Capell\Core\EventSourcing\Rollback\Actions\ApplyRollbackAction;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageRevision;
use Capell\Core\Models\PageWorkflowState;
use Capell\Core\Models\Site;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

final class PageHistoryFixture
{
    private const string LayoutKey = 'page-history-screenshot-fixture';

    private const string PageName = 'Page history example';

    public static function editUrl(): string
    {
        return PageResource::getUrl('edit', ['record' => self::rebuild()]);
    }

    private static function rebuild(): Page
    {
        $page = self::page();
        $layout = self::layout($page->site);

        if ($page->layout_id !== $layout->getKey()) {
            $page->forceFill(['layout_id' => $layout->getKey()])->save();
        }

        DB::transaction(function () use ($page): void {
            DB::table('stored_events')->where('aggregate_uuid', $page->uuid)->delete();
            DB::table('snapshots')->where('aggregate_uuid', $page->uuid)->delete();
            PageRevision::query()->where('page_uuid', $page->uuid)->delete();
            PageWorkflowState::query()->where('page_uuid', $page->uuid)->delete();

            self::recordRevision($page, 'Homepage launch copy', '<p>Plan and publish the launch page.</p>');
            self::recordRevision($page, 'Homepage launch copy reviewed', '<p>Review and approve the launch page.</p>');
            self::recordRevision($page, 'Homepage launch copy published', '<p>Publish the approved launch page.</p>');

            ApplyRollbackAction::run($page->refresh(), 2);
        });

        return $page->refresh();
    }

    private static function page(): Page
    {
        $page = Page::query()->firstOrNew(['name' => self::PageName]);

        if ($page->exists) {
            return $page;
        }

        $site = Site::query()->first();
        $layout = $site instanceof Site
            ? $site->layouts()->first() ?? Layout::query()->first()
            : null;
        $blueprint = Blueprint::query()->pageType()->first();

        throw_if(! ($site instanceof Site) || ! ($layout instanceof Layout) || ! ($blueprint instanceof Blueprint), ModelNotFoundException::class, 'The screenshot app must be seeded before building the page history fixture.');

        $page->fill([
            'site_id' => $site->getKey(),
            'layout_id' => $layout->getKey(),
            'blueprint_id' => $blueprint->getKey(),
            'visible_from' => now(),
        ])->save();

        return $page;
    }

    private static function layout(Site $site): Layout
    {
        return Layout::query()->updateOrCreate(
            [
                'site_id' => $site->getKey(),
                'key' => self::LayoutKey,
            ],
            [
                'name' => 'Page history example',
                'containers' => [],
            ],
        );
    }

    private static function recordRevision(Page $page, string $title, string $content): void
    {
        $page->translations()->updateOrCreate(
            ['language_id' => $page->site->language_id],
            [
                'title' => $title,
                'content' => $content,
                'meta' => ['slug' => 'page-history-example'],
            ],
        );

        $page->load(['translations', 'pageUrls']);
        $page->save();
    }
}
