<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Translations;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\Language;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Attach the language of every loaded page URL and translation without repeating reads.
 *
 * Eager-loading `pageUrls.language` and `translations.language` reads the same few
 * language rows once per relation, for every page a public request resolves. Load the
 * page with plain `pageUrls` and `translations`, then run this action: languages the
 * caller already holds (the request language) are reused, and the rest come from one
 * query. A single-language page therefore costs no language query at all.
 *
 * The container holds one instance per request (scoped), and it remembers the languages
 * it has read: a listing served through a fallback language hydrates every cold page
 * with the same fallback rows, so it reads them once rather than once per page.
 * LanguageObserver forgets the instance whenever a language changes.
 *
 * @method static void run(Model&Pageable<Model> $page, iterable<Language> $known = [])
 */
class HydratePageLanguagesAction
{
    use AsFake;
    use AsObject;

    private const array RELATIONS = ['pageUrls', 'translations'];

    /**
     * Languages this request has already read, keyed by id; null marks an id with no row.
     *
     * @var array<int, Language|null>
     */
    private array $read = [];

    /**
     * @param  Model&Pageable<Model>  $page
     * @param  iterable<Language>  $known
     */
    public function handle(Pageable&Model $page, iterable $known = []): void
    {
        $this->handleMany([$page], $known);
    }

    /**
     * @param  iterable<Model&Pageable<Model>>  $pages
     * @param  iterable<Language>  $known
     */
    public function handleMany(iterable $pages, iterable $known = []): void
    {
        /** @var Collection<int, Model> $rows */
        $rows = collect();

        foreach ($pages as $page) {
            $rows = $rows->concat($this->rows($page));
        }

        $ids = $rows
            ->map(static fn (Model $row): ?int => self::languageId($row))
            ->filter(static fn (?int $id): bool => $id !== null)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return;
        }

        /** @var Collection<int, Language> $languages */
        $languages = collect($known)
            ->filter(static fn (mixed $language): bool => $language instanceof Language && $language->exists && ! $language->trashed())
            // A detached copy: the page model cache stores these rows, and the request's
            // language may carry relations that do not belong in the cached payload.
            ->mapWithKeys(static fn (Language $language): array => [(int) $language->getKey() => $language->withoutRelations()])
            ->only($ids->all());

        $missing = $ids->diff($languages->keys())->reject(fn (int $id): bool => array_key_exists($id, $this->read))->values();

        if ($missing->isNotEmpty()) {
            $found = Language::query()->whereKey($missing->all())->get()->keyBy(static fn (Language $language): int => (int) $language->getKey());

            foreach ($missing as $id) {
                $this->read[$id] = $found->get($id);
            }
        }

        foreach ($ids->diff($languages->keys()) as $id) {
            $languages->put($id, $this->read[$id] ?? null);
        }

        $rows->each(static function (Model $row) use ($languages): void {
            $languageId = self::languageId($row);

            $row->setRelation('language', $languageId === null ? null : $languages->get($languageId));
        });
    }

    private static function languageId(Model $row): ?int
    {
        $languageId = $row->getAttribute('language_id');

        return is_numeric($languageId) ? (int) $languageId : null;
    }

    /**
     * @param  Model&Pageable<Model>  $page
     * @return Collection<int, Model>
     */
    private function rows(Pageable&Model $page): Collection
    {
        /** @var Collection<int, Model> $rows */
        $rows = collect();

        foreach (self::RELATIONS as $relation) {
            if (! $page->relationLoaded($relation)) {
                continue;
            }

            $related = $page->getRelation($relation);

            if ($related instanceof EloquentCollection) {
                $rows = $rows->concat($related->all());
            }
        }

        return $rows;
    }
}
