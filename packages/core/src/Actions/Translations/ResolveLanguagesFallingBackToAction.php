<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Translations;

use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Lists the other languages whose fallback chain reaches a language, so content
 * served from that language as a fallback can be invalidated when it changes.
 *
 * @method static list<int> run(int $languageId)
 */
final class ResolveLanguagesFallingBackToAction
{
    use AsFake;
    use AsObject;

    /**
     * @return list<int>
     */
    public function handle(int $languageId): array
    {
        $chains = ResolveLanguageFallbackChainAction::make();
        $dependents = [];

        foreach (array_keys($chains->languageMap()) as $candidateId) {
            if ($candidateId === $languageId) {
                continue;
            }

            if (in_array($languageId, array_slice($chains->handle($candidateId), 1), true)) {
                $dependents[] = $candidateId;
            }
        }

        return $dependents;
    }
}
