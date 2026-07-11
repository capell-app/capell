<?php

declare(strict_types=1);

namespace Capell\Core\Observers;

use Capell\Core\Enums\CacheEnum;
use Capell\Core\Models\Language;
use Capell\Core\Support\CapellCoreHelper;

class LanguageObserver
{
    public function saved(Language $language): void
    {
        CapellCoreHelper::flushCache([
            CacheEnum::HasDefaultLanguage,
            CacheEnum::LanguageByIdOrSite,
            CacheEnum::SiteLanguages,
            // raw non-enum cache keys
            'language-codes-by-ids',
            'languages-by-codes',
            CacheEnum::RelationExists,
        ]);
    }

    public function deleted(Language $language): void
    {
        $this->saved($language);
    }

    public function restored(Language $language): void
    {
        $this->saved($language);
    }
}
