<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Dashboard;

use Capell\Core\Models\Page;
use Illuminate\Database\Eloquent\Builder;

final class BuildDashboardPageBlockersAction
{
    /** @return list<string> */
    public function handle(Page $page): array
    {
        $page->loadMissing(['blueprint', 'layout']);
        if (! array_key_exists('translations_count', $page->getAttributes())) {
            $page->loadCount('translations');
        }

        if (! array_key_exists('active_url_count', $page->getAttributes())) {
            $page->loadCount(['pageUrls as active_url_count' => fn (Builder $query): Builder => $query->enabled()]);
        }

        $reasons = [];
        if ($page->blueprint === null) {
            $reasons[] = 'blueprint';
        }

        if ($page->layout === null) {
            $reasons[] = 'layout';
        }

        if ((int) $page->getAttribute('translations_count') === 0) {
            $reasons[] = 'translation';
        }

        if ((int) $page->getAttribute('active_url_count') === 0) {
            $reasons[] = 'url';
        }

        return $reasons;
    }
}
