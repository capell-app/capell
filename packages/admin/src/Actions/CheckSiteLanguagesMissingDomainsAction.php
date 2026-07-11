<?php

declare(strict_types=1);

namespace Capell\Admin\Actions;

use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;
use Illuminate\Database\Eloquent\Collection;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static Collection<int, Language> run(Site $site)
 */
class CheckSiteLanguagesMissingDomainsAction
{
    use AsObject;

    /**
     * @return Collection<int, Language>
     */
    public function handle(Site $site): Collection
    {
        $site->loadMissing('translations.language', 'siteDomains');

        return new Collection(
            $site
                ->translations
                ->reject(
                    fn (Translation $translation): bool => $site->siteDomains->contains('language_id', $translation->language_id),
                )
                ->map(fn (Translation $translation): Language => $translation->language)
                ->unique('id')
                ->values()
                ->all(),
        );
    }
}
