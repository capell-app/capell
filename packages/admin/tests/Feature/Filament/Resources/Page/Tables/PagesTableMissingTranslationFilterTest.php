<?php

declare(strict_types=1);

use Capell\Admin\Contracts\Extenders\PageTableExtender;
use Capell\Admin\Filament\Resources\Pages\Tables\PagesTable;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Translation;
use Filament\Tables\Filters\BaseFilter;
use Filament\Tables\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;

/**
 * @param  array<string, mixed>  $data
 * @return Builder<Page>
 */
function applyMissingTranslationFilter(array $data): Builder
{
    $method = new ReflectionMethod(PagesTable::class, 'applyMissingTranslationFilterQuery');

    /** @var Builder<Page> $builder */
    $builder = $method->invoke(null, Page::query(), $data);

    return $builder;
}

it('returns only pages without a translation for the chosen language', function (): void {
    $german = Language::factory()->createOne(['code' => 'de']);
    $french = Language::factory()->createOne(['code' => 'fr']);

    $translatedPage = Page::factory()->createOne();
    Translation::factory()
        ->translatable($translatedPage)
        ->language($german)
        ->createOne(['title' => 'Ein Titel']);

    $untranslatedPage = Page::factory()->createOne();
    Translation::factory()
        ->translatable($untranslatedPage)
        ->language($french)
        ->createOne(['title' => 'Un titre']);

    $pageWithoutAnyTranslation = Page::factory()->createOne();

    $matched = applyMissingTranslationFilter(['value' => $german->id])->pluck('id')->all();

    expect($matched)->toEqualCanonicalizing([$untranslatedPage->id, $pageWithoutAnyTranslation->id]);
});

it('treats a translation row without title or content as missing', function (): void {
    $german = Language::factory()->createOne(['code' => 'de']);

    $emptyRowPage = Page::factory()->createOne();
    Translation::factory()
        ->translatable($emptyRowPage)
        ->language($german)
        ->createOne(['title' => '', 'content' => '']);

    expect(applyMissingTranslationFilter(['value' => $german->id])->pluck('id')->all())
        ->toBe([$emptyRowPage->id]);
});

it('returns the unfiltered query for empty filter values', function (): void {
    $german = Language::factory()->createOne(['code' => 'de']);

    $page = Page::factory()->createOne();
    Translation::factory()
        ->translatable($page)
        ->language($german)
        ->createOne(['title' => 'Ein Titel']);

    expect(applyMissingTranslationFilter(['value' => null])->count())->toBe(1);
    expect(applyMissingTranslationFilter(['value' => ''])->count())->toBe(1);
});

it('keeps the filter within the extender and mutate composition', function (): void {
    app()->bind('missing-translation-extender-for-test', fn (): PageTableExtender => new class implements PageTableExtender
    {
        public function getColumns(): array
        {
            return [];
        }

        public function getBulkActions(): array
        {
            return [];
        }

        public function getFilters(): array
        {
            return [Filter::make('extender-filter')];
        }

        public function modifyQuery(Builder $query): Builder
        {
            return $query;
        }
    });

    app()->tag(['missing-translation-extender-for-test'], PageTableExtender::TAG);

    $table = new class extends PagesTable
    {
        /**
         * @param  list<BaseFilter>  $filters
         * @return list<BaseFilter>
         */
        protected static function mutateTableFilters(array $filters): array
        {
            return [...$filters, Filter::make('mutated-filter')];
        }
    };

    $method = new ReflectionMethod($table::class, 'getTableFilters');

    /** @var list<BaseFilter> $filters */
    $filters = $method->invoke(null);

    $names = array_map(fn (BaseFilter $filter): string => $filter->getName(), $filters);

    expect($names)->toContain('missing_translation')
        ->toContain('extender-filter')
        ->toContain('mutated-filter');
});
