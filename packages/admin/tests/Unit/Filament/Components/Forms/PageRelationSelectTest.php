<?php

declare(strict_types=1);

use Capell\Admin\Filament\Components\Forms\PageRelationSelect;
use Capell\Core\Enums\BlueprintGroupEnum;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Filament\Actions\Action;
use Illuminate\Database\Eloquent\Builder;

beforeEach(function (): void {
    test()->actingAsAdmin();
});

it('filters relation options by site page group and custom query callbacks', function (): void {
    $site = Site::factory()->withTranslations()->createOne();
    $otherSite = Site::factory()->withTranslations()->createOne();
    $pageType = Blueprint::factory()->page()->createOne(['key' => 'standard', 'group' => null]);
    $articleType = Blueprint::factory()->page()->createOne(['key' => 'article', 'group' => 'article']);
    $systemType = Blueprint::factory()->page()->group(BlueprintGroupEnum::System->value)->createOne(['key' => 'system']);

    $visiblePage = Page::factory()->site($site)->blueprint($pageType)->createOne(['name' => 'Visible page']);
    Page::factory()->site($site)->blueprint($articleType)->createOne(['name' => 'Article page']);
    Page::factory()->site($site)->blueprint($systemType)->createOne(['name' => 'System page']);
    Page::factory()->site($otherSite)->blueprint($pageType)->createOne(['name' => 'Other site page']);
    Page::factory()->site($site)->blueprint($pageType)->createOne(['name' => 'Hidden page']);

    $component = PageRelationSelect::make('parent_id')
        ->pageGroup('page')
        ->modifyTypeQueryUsing(fn (Builder $query): Builder => $query->where('key', 'standard'))
        ->modifyRelationQueryUsing(fn (Builder $query): Builder => $query->where('name', '!=', 'Hidden page'));

    $query = pageRelationSelectModifiedQuery($component, Page::query(), 'edit', $site->getKey());

    expect($query->pluck('name')->all())->toBe(['Visible page'])
        ->and($query->first()?->is($visiblePage))->toBeTrue()
        ->and($component->getModifyRelationQueryUsing())->toBeInstanceOf(Closure::class)
        ->and($component->getModifyTypeQueryUsing())->toBeInstanceOf(Closure::class);
});

it('accepts page group query closures and rejects unqualified foreign keys', function (): void {
    $site = Site::factory()->withTranslations()->createOne();
    $pageType = Blueprint::factory()->page()->createOne(['key' => 'standard', 'group' => null]);
    $articleType = Blueprint::factory()->page()->createOne(['key' => 'article', 'group' => 'article']);

    Page::factory()->site($site)->blueprint($pageType)->createOne(['name' => 'Standard page']);
    $article = Page::factory()->site($site)->blueprint($articleType)->createOne(['name' => 'Article page']);

    $component = PageRelationSelect::make('parent_id')
        ->pageGroup(fn (Builder $query): Builder => $query->where('key', 'article'));

    $query = pageRelationSelectModifiedQuery($component, Page::query(), 'create', $site->getKey());

    expect($query->pluck('name')->all())->toBe(['Article page'])
        ->and($query->first()?->is($article))->toBeTrue();

    expect(fn (): PageRelationSelect => PageRelationSelect::make('parent_id')->qualifiedForeignKeyName('page_id'))
        ->toThrow(InvalidArgumentException::class, 'must contain the table alias/name');
});

it('only exposes the hint edit action for persisted selected pages', function (): void {
    $site = Site::factory()->withTranslations()->createOne();
    $pageType = Blueprint::factory()->page()->createOne(['key' => 'standard']);
    $page = Page::factory()->site($site)->blueprint($pageType)->createOne(['name' => 'Editable page']);

    $component = PageRelationSelect::make('parent_id')->withHintEditAction();
    $hintAction = collect($component->getHintActions())->first();

    throw_unless($hintAction instanceof Action, RuntimeException::class, 'Expected PageRelationSelect hint edit action.');

    expect(evaluatePageRelationActionProperty($hintAction, 'isVisible', [
        'operation' => 'edit',
        'state' => $page->getKey(),
    ]))->toBeTrue()
        ->and(evaluatePageRelationActionProperty($hintAction, 'isVisible', [
            'operation' => 'create',
            'state' => $page->getKey(),
        ]))->toBeFalse()
        ->and(evaluatePageRelationActionProperty($hintAction, 'isVisible', [
            'operation' => 'edit',
            'state' => null,
        ]))->toBeFalse()
        ->and(evaluatePageRelationActionProperty($hintAction, 'url', [
            'state' => $page->getKey(),
        ]))->toContain((string) $page->getKey())
        ->and(evaluatePageRelationActionProperty($hintAction, 'url', [
            'state' => null,
        ]))->toBeNull()
        ->and(evaluatePageRelationActionProperty($hintAction, 'url', [
            'state' => 999999,
        ]))->toBeNull();
});

/**
 * @param  Builder<Page>  $query
 * @return Builder<Page>
 */
function pageRelationSelectModifiedQuery(PageRelationSelect $component, Builder $query, string $operation, ?int $siteId): Builder
{
    $callback = Closure::bind(
        fn (Builder $query, string $operation, ?int $siteId): Builder => $this->modifyRelationQuery($query, $operation, $siteId),
        $component,
        PageRelationSelect::class,
    );

    return $callback($query, $operation, $siteId);
}

/**
 * @param  array<string, mixed>  $namedInjections
 */
function evaluatePageRelationActionProperty(Action $action, string $property, array $namedInjections = []): mixed
{
    $reflectionProperty = new ReflectionProperty($action, $property);

    return $action->evaluate($reflectionProperty->getValue($action), $namedInjections);
}
