<?php

declare(strict_types=1);

use Capell\Admin\Filament\Components\Tables\Columns\Page\PageNameColumn;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

it('uses preloaded ancestors when formatting ancestor HTML', function (): void {
    $page = new Page(['name' => 'Child']);
    $page->setRelation('ancestors', new Collection([
        new Page(['name' => 'Parent']),
        new Page(['name' => 'Section']),
    ]));

    $queries = 0;
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        if (str_contains($query->sql, 'pages')) {
            $queries++;
        }
    });

    $method = new ReflectionMethod(PageNameColumn::class, 'getAncestorsHtml');

    $html = $method->invoke(PageNameColumn::make('name'), $page);

    expect($html?->toHtml())->toBe('Parent &raquo; Section')
        ->and($queries)->toBe(0);
});

it('renders linked names child counts type icons and deleted page colours', function (): void {
    $type = Blueprint::factory()->page()->createOne([
        'admin' => [
            'icon' => 'heroicon-o-document-text',
        ],
    ]);

    $page = Page::factory()->blueprint($type)->createOne([
        'name' => 'Child page',
        'deleted_at' => now(),
    ]);
    $page->setAttribute('children_count', 2);
    $page->setRelation('blueprint', $type);

    $column = PageNameColumn::make('name')
        ->nameUrl(fn (Page $record): string => '/admin/pages/' . $record->getKey())
        ->withTypeIcon()
        ->record($page);

    $html = (string) $column->formatState('Child page');

    expect($html)->toContain('/admin/pages/' . $page->getKey())
        ->and($html)->toContain('Child page')
        ->and($html)->toContain('(2)')
        ->and($column->getColor('Child page'))->toBe('danger')
        ->and($column->getIcon('Child page'))->toBe('heroicon-o-document-text')
        ->and((string) PageNameColumn::make('name')->children(false)->record($page)->formatState('Child page'))->not->toContain('(2)');
});

it('renders ancestor and URL descriptions from loaded page relationships', function (): void {
    $site = Site::factory()->withTranslations(siteDomainData: ['domain' => 'example.test', 'scheme' => 'https'])->createOne();
    $type = Blueprint::factory()->page()->createOne(['key' => 'standard']);
    $section = Page::factory()->site($site)->blueprint($type)->createOne(['name' => 'Section']);
    $parent = Page::factory()->site($site)->blueprint($type)->createOne(['name' => 'Parent']);
    $page = Page::factory()->site($site)->blueprint($type)->createOne(['name' => 'Child']);

    PageUrl::factory()
        ->site($site)
        ->page($page)
        ->createOne([
            'language_id' => $site->language_id,
            'url' => '/child',
        ]);

    $page->setRelation('ancestors', new Collection([$section, $parent]));
    $page->load('pageUrl.siteDomain');

    $ancestorDescription = PageNameColumn::make('name')
        ->ancestorsDescription()
        ->record($page)
        ->getDescriptionBelow();

    $prefixColumn = PageNameColumn::make('name')
        ->ancestorsPrefix()
        ->record($page);
    $prefixedState = evaluatePageNameColumnProperty($prefixColumn, 'getStateUsing', $page);

    $parentDescription = PageNameColumn::make('name')
        ->withParents()
        ->record($page)
        ->getDescriptionBelow();

    $urlDescription = PageNameColumn::make('name')
        ->urlDescription()
        ->record($page)
        ->getDescriptionBelow();

    assert($ancestorDescription instanceof Htmlable);
    assert($prefixedState instanceof Htmlable);
    assert($parentDescription instanceof Htmlable);
    assert($urlDescription instanceof Htmlable);

    expect($ancestorDescription->toHtml())->toBe('Section &raquo; Parent')
        ->and($prefixedState->toHtml())->toBe('Section &raquo; Parent &raquo; Child')
        ->and($parentDescription->toHtml())->toContain('Section Parent')
        ->and($urlDescription->toHtml())->toContain('/child')
        ->and($urlDescription->toHtml())->toContain('https://example.test');
});

it('formats related pageable records and reports missing page relationships', function (): void {
    $site = Site::factory()->withTranslations()->createOne();
    $type = Blueprint::factory()->page()->createOne(['key' => 'standard']);
    $page = Page::factory()->site($site)->blueprint($type)->createOne(['name' => 'Related page']);
    $page->setAttribute('children_count', 0);

    $pageUrl = PageUrl::factory()->site($site)->page($page)->createOne(['url' => '/related']);
    $pageUrl->setRelation('pageable', $page);

    $brokenPageUrl = PageUrl::factory()->make();
    $brokenPageUrl->setRelation('pageable', null);

    $html = (string) PageNameColumn::make('pageable.name')
        ->record($pageUrl)
        ->formatState('Related page');

    expect($html)->toContain('Related page');

    expect(fn (): mixed => PageNameColumn::make('pageable.name')
        ->record($brokenPageUrl)
        ->formatState('Broken page'))->toThrow(Exception::class, 'Page relation not found.');
});

function evaluatePageNameColumnProperty(PageNameColumn $column, string $property, Model $record): mixed
{
    $reflectionProperty = new ReflectionProperty($column, $property);

    return $column->evaluate($reflectionProperty->getValue($column), [
        'record' => $record,
    ], [
        Model::class => $record,
        $record::class => $record,
    ]);
}
