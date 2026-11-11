<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\EventSourcing\Actions\RecordPageVersionAction;
use Capell\Core\EventSourcing\Enums\PageVersionSource;
use Capell\Core\EventSourcing\Support\EventSourcedRegistry;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageRevision;
use Capell\Core\Models\Site;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class RecordImportedPageVersionsAction
{
    use AsFake;
    use AsObject;

    /**
     * Imported pages are created before their translations, so no save records
     * a complete version. Give every imported page without one its first
     * version, so editors can always return to what the import produced.
     */
    public function handle(Site $site): void
    {
        if (! resolve(EventSourcedRegistry::class)->isRegistered(Page::class)) {
            return;
        }

        $site->pages()
            ->whereNotIn('uuid', PageRevision::query()->select('page_uuid'))
            ->with(['translations', 'pageUrls'])
            ->each(static function (Page $page): void {
                if ($page->translations->isNotEmpty()) {
                    RecordPageVersionAction::run($page, PageVersionSource::Import);
                }
            });
    }
}
