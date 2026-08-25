<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Pages;

use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Scopes\LanguagesOrderScope;
use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class BuildPageListingQueryAction
{
    use AsFake;
    use AsObject;

    /**
     * Eager-loads a page listing's translation and URL for the effective
     * language: the table filter's language when one is set, otherwise the
     * site's default language. $filterLanguageId is the raw table filter
     * state value, taken as-is (unset/null/empty string all mean "no
     * filter"), matching how Filament reports an unselected select filter.
     *
     * @param  Builder<Page>  $query
     * @return Builder<Page>
     */
    public function handle(Builder $query, mixed $filterLanguageId): Builder
    {
        $hasLanguageFilter = $filterLanguageId !== null && $filterLanguageId !== '';
        $languageId = $hasLanguageFilter ? $filterLanguageId : $this->defaultLanguageId();

        return $query->with([
            'translation' => fn (BuilderContract $query): BuilderContract => $this->applyLanguageFilter($query, $hasLanguageFilter, $languageId),
            'pageUrl' => fn (BuilderContract $query): BuilderContract => $this->applyLanguageFilter($query, $hasLanguageFilter, $languageId)
                ->with('siteDomain'),
        ]);
    }

    private function defaultLanguageId(): mixed
    {
        /** @var class-string<Language> $model */
        $model = Language::class;

        return $model::query()->default()->value('id');
    }

    private function applyLanguageFilter(BuilderContract $query, bool $hasLanguageFilter, mixed $languageId): BuilderContract
    {
        if ($languageId === null || $languageId === 0) {
            return $query;
        }

        return $query->when(
            $hasLanguageFilter,
            fn (BuilderContract $query): BuilderContract => $query->where('language_id', $languageId),
            fn (BuilderContract $query): BuilderContract => LanguagesOrderScope::applyTo($query, [$languageId]),
        );
    }
}
