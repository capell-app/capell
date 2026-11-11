<?php

declare(strict_types=1);

namespace Capell\Core\Observers;

use Capell\Core\Actions\Translations\ResolveLanguageFallbackChainAction;
use Capell\Core\Actions\Translations\ValidateLanguageFallbackAction;
use Capell\Core\Enums\CacheEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Language;
use Capell\Core\Support\CapellCoreHelper;
use Illuminate\Validation\ValidationException;

class LanguageObserver
{
    /**
     * Rejects a fallback that points at the language itself or loops back to it,
     * whichever path saved it (admin, API or SiteSpec import).
     *
     * @throws ValidationException
     */
    public function saving(Language $language): void
    {
        if (! $language->isDirty('fallback_language_id')) {
            return;
        }

        $reason = ValidateLanguageFallbackAction::run(
            $language->exists ? (int) $language->getKey() : null,
            $language->fallback_language_id,
        );

        if ($reason !== null) {
            throw ValidationException::withMessages(['fallback_language_id' => $reason]);
        }
    }

    public function saved(Language $language): void
    {
        CapellCore::removeCacheKey(CacheEnum::LanguageLocales->value);
        CapellCore::removeCacheKey(ResolveLanguageFallbackChainAction::CACHE_KEY);

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
