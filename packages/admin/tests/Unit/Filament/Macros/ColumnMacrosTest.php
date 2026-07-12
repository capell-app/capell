<?php

declare(strict_types=1);

use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Filament\Tables\Columns\TextColumn;

it('links table column states and related pageable records to their edit pages', function (): void {
    $type = Blueprint::factory()->page()->createOne(['key' => 'standard']);
    $page = Page::factory()->blueprint($type)->createOne(['name' => 'Linked page']);
    $pageUrl = PageUrl::factory()->page($page)->site($page->site)->createOne(['url' => '/linked']);
    $pageUrl->setRelation('pageable', $page);

    $stateUrl = TextColumn::make('name')
        ->linkRecord()
        ->record($page)
        ->getUrl($page);

    $relatedUrl = TextColumn::make('pageable.name')
        ->linkRecord()
        ->record($pageUrl)
        ->getUrl('Linked page');

    $missingPageUrl = PageUrl::factory()->make([
        'pageable_type' => null,
        'pageable_id' => null,
    ]);
    $missingPageUrl->setRelation('pageable', null);

    $missingRelationUrl = TextColumn::make('pageable.name')
        ->linkRecord()
        ->record($missingPageUrl)
        ->getUrl('Missing page');

    $nonModelUrl = TextColumn::make('name')
        ->linkRecord()
        ->getUrl('Missing record');

    expect($stateUrl)->toContain((string) $page->getKey())
        ->and($relatedUrl)->toContain((string) $page->getKey())
        ->and($missingRelationUrl)->toBeNull()
        ->and($nonModelUrl)->toBeNull();
});
