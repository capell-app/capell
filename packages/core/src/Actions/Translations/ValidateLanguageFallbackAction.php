<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Translations;

use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Explains why a language cannot use a fallback language, or returns null when
 * it can. A language may not fall back to itself, nor to a language whose own
 * chain leads back to it. Admin forms, SiteSpec import and LanguageObserver all
 * call this so a misconfigured chain is rejected the same way everywhere.
 *
 * @method static string|null run(?int $languageId, ?int $fallbackLanguageId)
 */
final class ValidateLanguageFallbackAction
{
    use AsFake;
    use AsObject;

    public function handle(?int $languageId, ?int $fallbackLanguageId): ?string
    {
        if ($fallbackLanguageId === null || $languageId === null) {
            return null;
        }

        if ($fallbackLanguageId === $languageId) {
            return __('capell-core::message.language_fallback_self');
        }

        $languages = ResolveLanguageFallbackChainAction::make()->languageMap();
        $visited = [];
        $nextId = $fallbackLanguageId;

        while ($nextId !== null && ! in_array($nextId, $visited, true)) {
            if ($nextId === $languageId) {
                return __('capell-core::message.language_fallback_cycle');
            }

            $visited[] = $nextId;
            $nextId = $languages[$nextId]['fallback'] ?? null;
        }

        return null;
    }
}
