<?php

declare(strict_types=1);

namespace Capell\Core\Listeners;

use Capell\Core\Actions\UpdatePageUrlAction;
use Capell\Core\Contracts\Pageable;
use Capell\Core\Enums\CacheEnum;
use Capell\Core\Models\Language;
use Capell\Core\Models\Translation;
use Capell\Core\Support\CapellCoreHelper;
use Illuminate\Database\Eloquent\Model;

final class PageTranslationSavedListener
{
    public function __invoke(Translation $translation): void
    {
        if (! $translation->isPage()) {
            return;
        }

        /** @var Pageable<Model>&Model $page */
        $page = $translation->translatable()->first();
        $language = $translation->language()->first();

        if (! $page instanceof Model || ! $page instanceof Pageable) {
            return;
        }

        if (! $language instanceof Language) {
            return;
        }

        $url = $page->getParentUrl($language);

        UpdatePageUrlAction::run($page->site, $translation, $url);

        CapellCoreHelper::flushCache([
            CacheEnum::SiteLanguages->value,
            CacheEnum::FirstPageByTypeForSite->value,
            CacheEnum::RelationExists->value,
        ]);
    }
}
