<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Properties;

use Capell\Core\Actions\PageSavedAction;
use Capell\Core\Models\Page;
use Capell\Core\Models\Term;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Replaces a page's ordered term assignments after enforcing the page's site
 * boundary. The PageSaved event keeps revisions and frontend invalidation on
 * the same lifecycle as other authoring writes.
 */
final class AssignPageTermsAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  list<int>  $termIds
     */
    public function handle(Page $page, array $termIds): void
    {
        $termIds = $this->normaliseIds($termIds);

        $terms = Term::query()
            ->whereIn('id', $termIds)
            ->whereHas('taxonomy', static fn (Builder $query): Builder => $query->where('site_id', $page->site_id))
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        if (count($terms) !== count($termIds) || array_diff($termIds, $terms) !== []) {
            throw ValidationException::withMessages([
                'term_ids' => __('capell-core::properties.validation.terms_out_of_scope'),
            ]);
        }

        $assignments = [];
        foreach ($termIds as $position => $termId) {
            $assignments[$termId] = ['position' => $position];
        }

        DB::transaction(function () use ($page, $assignments, $termIds): void {
            $page->terms()->sync($assignments);
            PageSavedAction::run($page, ['_term_ids' => $termIds]);
        });
    }

    /**
     * @param  array<int, int|string>  $termIds
     * @return list<int>
     */
    private function normaliseIds(array $termIds): array
    {
        foreach ($termIds as $termId) {
            if (! is_int($termId) && (! is_string($termId) || ! ctype_digit($termId))) {
                throw ValidationException::withMessages([
                    'term_ids' => __('capell-core::properties.validation.terms_invalid'),
                ]);
            }
        }

        /** @var list<int> $normalised */
        $normalised = array_values(array_map(intval(...), $termIds));

        if (count($normalised) !== count(array_unique($normalised)) || in_array(0, $normalised, true)) {
            throw ValidationException::withMessages([
                'term_ids' => __('capell-core::properties.validation.terms_invalid'),
            ]);
        }

        return $normalised;
    }
}
