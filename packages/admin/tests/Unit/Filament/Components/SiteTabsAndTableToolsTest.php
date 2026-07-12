<?php

declare(strict_types=1);

use Capell\Admin\Filament\Components\Forms\Editor\TinyEditor;
use Capell\Admin\Filament\Components\Forms\Site\Tab\MediaTab;
use Capell\Admin\Filament\Components\Tables\Actions\VisitUrlAction;
use Capell\Admin\Filament\Components\Tables\Filters\PageSelectFilter;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Illuminate\Database\Eloquent\Builder;

it('builds the site media tab schema', function (): void {
    $tab = MediaTab::make();

    expect($tab->getLabel())->toBe(__('capell-admin::tab.media'));
});

it('configures page select filter defaults and fluent options', function (): void {
    $filter = PageSelectFilter::make()->multiple()->modifySelectQueryUsing(fn (Builder $query): Builder => $query);

    expect(PageSelectFilter::getDefaultName())->toBe('page_filter')
        ->and($filter)->toBeInstanceOf(PageSelectFilter::class);
});

it('filters pageable table records and describes the selected pages', function (): void {
    $site = Site::factory()->withTranslations()->createOne();
    $type = Blueprint::factory()->page()->createOne(['key' => 'standard']);
    $alphaPage = Page::factory()->site($site)->blueprint($type)->createOne(['name' => 'Alpha page']);
    $betaPage = Page::factory()->site($site)->blueprint($type)->createOne(['name' => 'Beta page']);
    $otherPage = Page::factory()->site($site)->blueprint($type)->createOne(['name' => 'Other page']);

    $alphaUrl = PageUrl::factory()->site($site)->page($alphaPage)->createOne(['url' => '/alpha']);
    $betaUrl = PageUrl::factory()->site($site)->page($betaPage)->createOne(['url' => '/beta']);
    PageUrl::factory()->site($site)->page($otherPage)->createOne(['url' => '/other']);

    $filter = PageSelectFilter::make()->multiple();
    $state = [
        'pageable_type' => $alphaPage->getMorphClass(),
        'pageable_id' => [$alphaPage->getKey(), $betaPage->getKey()],
    ];

    $filteredIds = $filter
        ->apply(PageUrl::query(), $state)
        ->pluck('id')
        ->all();

    expect($filteredIds)->toBe([$alphaUrl->getKey(), $betaUrl->getKey()])
        ->and(pageSelectFilterIndicatorForTest($filter, $state))->toBe(
            __('capell-admin::filter.page', ['search' => 'Alpha page, Beta page']),
        )
        ->and(pageSelectFilterIndicatorForTest($filter, []))->toBeNull()
        ->and($filter->apply(PageUrl::query(), [])->count())->toBe(3)
        ->and($filter->apply(PageUrl::query(), [
            'pageable_type' => '',
            'pageable_id' => '',
        ])->count())->toBe(3);
});

it('configures visit url action defaults', function (): void {
    $action = VisitUrlAction::make();

    expect(VisitUrlAction::getDefaultName())->toBe('visit-page')
        ->and($action->getLabel())->toBe(__('capell-admin::button.visit_page'));
});

it('configures tiny editor defaults for admin content fields', function (): void {
    expect(TinyEditor::make('body')->getMinHeight())->toBe(300);
});

/**
 * @param  array<string, mixed>  $state
 */
function pageSelectFilterIndicatorForTest(PageSelectFilter $filter, array $state): ?string
{
    $reflectionProperty = new ReflectionProperty($filter, 'indicateUsing');

    return $filter->evaluate($reflectionProperty->getValue($filter), [
        'data' => $state,
        'state' => $state,
    ]);
}
