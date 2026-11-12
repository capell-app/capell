<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Translations;

use Capell\Core\Models\Language;
use Closure;
use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Limits a page query to the pages a language can serve: those with a translation (and,
 * when asked, a usable URL) in the language itself, plus those with no translation in it
 * that a fallback language can serve. A page translated into the language is only ever
 * counted through its own rows, so fallback never changes which pages it lists or how
 * they read. Languages with no fallback keep the exact-language constraint.
 *
 * This is the listing counterpart of ResolveTranslationAction: PageModelCache hydrates
 * the translation each matched page is served with.
 *
 * @method static void run(BuilderContract $query, Language|int $language, ?Closure $urlConstraint = null)
 */
final class ConstrainToServedTranslationAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  BuilderContract  $query  A query on a model with translation(s) and pageUrl relations
     * @param  (Closure(BuilderContract, list<int>): mixed)|null  $urlConstraint  Narrows the page URL relation to the given language ids; null skips the URL requirement
     */
    public function handle(BuilderContract $query, Language|int $language, ?Closure $urlConstraint = null): void
    {
        $chain = ResolveLanguageFallbackChainAction::run($language);
        $requestedId = $chain[0];
        $fallbackIds = array_slice($chain, 1);

        $query->where(function (BuilderContract $served) use ($requestedId, $fallbackIds, $urlConstraint): void {
            $served->where(function (BuilderContract $own) use ($requestedId, $urlConstraint): void {
                $own->whereHas('translation', fn (BuilderContract $translation): BuilderContract => $translation->where('language_id', $requestedId));

                if ($urlConstraint instanceof Closure) {
                    $own->whereHas('pageUrl', fn (BuilderContract $url): mixed => $urlConstraint($url, [$requestedId]));
                }
            });

            if ($fallbackIds === []) {
                return;
            }

            $served->orWhere(function (BuilderContract $fallback) use ($requestedId, $fallbackIds, $urlConstraint): void {
                $fallback
                    ->whereDoesntHave('translations', fn (BuilderContract $translation): BuilderContract => $translation->where('language_id', $requestedId))
                    ->whereHas('translations', fn (BuilderContract $translation): BuilderContract => $translation->whereIn('language_id', $fallbackIds));

                if ($urlConstraint instanceof Closure) {
                    $fallback->whereHas('pageUrl', fn (BuilderContract $url): mixed => $urlConstraint($url, [$requestedId, ...$fallbackIds]));
                }
            });
        });
    }
}
