<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Translations;

use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Language;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Lists the languages to try, in order, when content is requested in a language:
 * the requested language first, then each configured fallback. The walk stops at
 * a language that is disabled, deleted or already visited, so a misconfigured
 * cycle ends instead of looping, and it never exceeds MAX_DEPTH fallbacks.
 *
 * The language map is cached under CACHE_KEY and flushed by LanguageObserver, so
 * render paths resolve chains without a query once the cache is warm.
 *
 * @method static list<int> run(Language|int $language)
 */
final class ResolveLanguageFallbackChainAction
{
    use AsFake;
    use AsObject;

    public const int MAX_DEPTH = 5;

    public const string CACHE_KEY = 'language-fallback-map';

    /**
     * @return list<int>
     */
    public function handle(Language|int $language): array
    {
        $requestedId = $language instanceof Language ? (int) $language->getKey() : $language;
        $languages = $this->languageMap();
        $nextId = $language instanceof Language
            ? $language->fallback_language_id
            : ($languages[$requestedId]['fallback'] ?? null);

        $chain = [$requestedId];

        while ($nextId !== null && count($chain) <= self::MAX_DEPTH) {
            $next = $languages[$nextId] ?? null;

            if ($next === null || ! $next['enabled'] || in_array($nextId, $chain, true)) {
                break;
            }

            $chain[] = $nextId;
            $nextId = $next['fallback'];
        }

        return $chain;
    }

    /**
     * Every language's configured fallback and enabled state, keyed by language id.
     *
     * @return array<int, array{fallback: int|null, enabled: bool}>
     */
    public function languageMap(): array
    {
        /** @var array<int, array{fallback: int|null, enabled: bool}> $languages */
        $languages = CapellCore::rememberCache(self::CACHE_KEY, static fn (): array => Language::query()
            ->get(['id', 'fallback_language_id', 'status'])
            ->mapWithKeys(static fn (Language $language): array => [
                (int) $language->getKey() => [
                    'fallback' => $language->fallback_language_id,
                    'enabled' => $language->status,
                ],
            ])
            ->all());

        return $languages;
    }
}
