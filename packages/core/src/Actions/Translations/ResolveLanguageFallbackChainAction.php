<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Translations;

use Capell\Core\Models\Language;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Lists the languages to try, in order, when content is requested in a language:
 * the requested language first, then each configured fallback. The walk stops at
 * a language that is disabled, deleted or already visited, so a misconfigured
 * cycle ends instead of looping, and it never exceeds MAX_DEPTH fallbacks.
 *
 * @method static list<int> run(Language|int $language)
 */
final class ResolveLanguageFallbackChainAction
{
    use AsFake;
    use AsObject;

    public const int MAX_DEPTH = 5;

    /**
     * @return list<int>
     */
    public function handle(Language|int $language): array
    {
        $requestedId = $language instanceof Language ? (int) $language->getKey() : $language;
        $nextId = $language instanceof Language
            ? $language->fallback_language_id
            : $this->fallbackIdOf($requestedId);

        if ($nextId === null) {
            return [$requestedId];
        }

        /** @var array<int, int|null> $fallbacks */
        $fallbacks = Language::query()
            ->enabled()
            ->pluck('fallback_language_id', 'id')
            ->map(static fn (mixed $id): ?int => $id === null ? null : (int) $id)
            ->all();

        $chain = [$requestedId];

        while ($nextId !== null && count($chain) <= self::MAX_DEPTH) {
            if (! array_key_exists($nextId, $fallbacks) || in_array($nextId, $chain, true)) {
                break;
            }

            $chain[] = $nextId;
            $nextId = $fallbacks[$nextId];
        }

        return $chain;
    }

    private function fallbackIdOf(int $languageId): ?int
    {
        $fallbackId = Language::query()->whereKey($languageId)->value('fallback_language_id');

        return $fallbackId === null ? null : (int) $fallbackId;
    }
}
