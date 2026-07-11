<?php

declare(strict_types=1);

use Capell\Core\Actions\ResolvePageableMorphModelAction;
use Capell\Core\Models\Page;

it('resolves a pageable morph model by type and id', function (): void {
    $page = Page::factory()->createOne([
        'name' => 'Landing Page',
    ]);

    $resolvedModel = ResolvePageableMorphModelAction::run(
        $page->getMorphClass(),
        $page->getKey(),
        ['id', 'name'],
    );

    expect($resolvedModel)
        ->not()->toBeNull()
        ->and($resolvedModel)->toBeInstanceOf(Page::class)
        ->and($resolvedModel?->getAttribute('name'))->toBe('Landing Page');
});

it('returns null for unknown morph type and missing record', function (): void {
    $page = Page::factory()->createOne();

    $unknownMorphResolvedModel = ResolvePageableMorphModelAction::run('unknown-morph-type', $page->getKey(), ['name']);
    $missingRecordResolvedModel = ResolvePageableMorphModelAction::run($page->getMorphClass(), -1, ['name']);

    expect($unknownMorphResolvedModel)->toBeNull()
        ->and($missingRecordResolvedModel)->toBeNull();
});
