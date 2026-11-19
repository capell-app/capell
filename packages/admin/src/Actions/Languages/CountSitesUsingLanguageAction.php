<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Languages;

use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Capell\Core\Support\Permissions\SiteAccess;
use Illuminate\Database\Eloquent\Builder;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Counts the sites in the actor's scope that use a language through a domain or as their own
 * language, each site once however many domains it has in that language.
 */
final class CountSitesUsingLanguageAction
{
    use AsFake;
    use AsObject;

    public function handle(Language $language): int
    {
        return SiteAccess::current()
            ->query(Site::class)
            ->where(fn (Builder $query): Builder => $query
                ->whereHas('siteDomains', fn (Builder $domains): Builder => $domains->where('language_id', $language->getKey()))
                ->orWhere('language_id', $language->getKey()))
            ->count();
    }
}
