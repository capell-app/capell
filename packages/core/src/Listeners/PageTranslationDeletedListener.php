<?php

declare(strict_types=1);

namespace Capell\Core\Listeners;

use Capell\Core\Enums\CacheEnum;
use Capell\Core\Models\Translation;
use Capell\Core\Support\CapellCoreHelper;

final class PageTranslationDeletedListener
{
    public function __invoke(Translation $translation): void
    {
        if (! $translation->isPageable()) {
            return;
        }

        CapellCoreHelper::flushCache([
            CacheEnum::SiteLanguages->value,
            CacheEnum::FirstPageByTypeForSite->value,
            CacheEnum::RelationExists->value,
        ]);
    }
}
