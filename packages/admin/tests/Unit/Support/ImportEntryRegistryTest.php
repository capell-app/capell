<?php

declare(strict_types=1);

use Capell\Admin\Data\ImportEntryData;
use Capell\Admin\Filament\Resources\Pages\Pages\ListPages;
use Capell\Admin\Filament\Resources\Sites\Pages\ListSites;
use Capell\Admin\Support\ImportEntryRegistry;
use Filament\Actions\Action;

it('orders import entries for a page by sort then key', function (): void {
    $registry = new ImportEntryRegistry;

    $registry->register(importEntryForRegistryTest('media', 20, [ListPages::class]));
    $registry->register(importEntryForRegistryTest('pages', 10, [ListPages::class]));
    $registry->register(importEntryForRegistryTest('blueprints', 10, [ListPages::class]));

    expect(array_map(
        fn (ImportEntryData $entry): string => $entry->key,
        $registry->forPage(ListPages::class),
    ))->toBe(['blueprints', 'pages', 'media']);
});

it('filters import entries by page class', function (): void {
    $registry = new ImportEntryRegistry;

    $registry->register(importEntryForRegistryTest('pages', 10, [ListPages::class]));
    $registry->register(importEntryForRegistryTest('sites', 20, [ListSites::class]));

    expect($registry->forPage(ListPages::class))->toHaveCount(1)
        ->and($registry->forPage(ListPages::class)[0]->key)->toBe('pages')
        ->and($registry->forPage(ListSites::class))->toHaveCount(1)
        ->and($registry->forPage(ListSites::class)[0]->key)->toBe('sites');
});

it('replaces duplicate import entry keys', function (): void {
    $registry = new ImportEntryRegistry;

    $registry->register(importEntryForRegistryTest('pages', 10, [ListPages::class]));
    $registry->register(importEntryForRegistryTest('pages', 5, [ListSites::class]));

    expect($registry->forPage(ListPages::class))->toBe([])
        ->and($registry->forPage(ListSites::class))->toHaveCount(1)
        ->and($registry->forPage(ListSites::class)[0]->sort)->toBe(5);
});

it('separates registered entries from entries visible to the current actor', function (): void {
    $registry = new ImportEntryRegistry;

    $registry->register(importEntryForRegistryTest(
        key: 'pages',
        sort: 10,
        pageClasses: [ListPages::class],
        authorize: fn (): bool => false,
    ));

    expect($registry->registeredForPage(ListPages::class))->toHaveCount(1)
        ->and($registry->forPage(ListPages::class))->toBe([]);
});

/**
 * @param  list<class-string>  $pageClasses
 */
function importEntryForRegistryTest(string $key, int $sort, array $pageClasses, ?Closure $authorize = null): ImportEntryData
{
    return new ImportEntryData(
        key: $key,
        labelKey: 'capell-admin::exchanger.import.action_label',
        descriptionKey: null,
        icon: 'heroicon-o-arrow-up-tray',
        sort: $sort,
        pageClasses: $pageClasses,
        actionFactory: fn (): Action => Action::make($key),
        authorize: $authorize,
    );
}
