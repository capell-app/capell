<?php

declare(strict_types=1);

use Capell\Core\Actions\Properties\CreatePropertySetAction;
use Capell\Core\Actions\Properties\UpdatePropertySetAction;
use Capell\Core\Actions\Taxonomies\CreateTaxonomyAction;
use Capell\Core\Actions\Taxonomies\UpdateTaxonomyAction;
use Capell\Core\Models\PropertySet;
use Capell\Core\Models\Site;
use Capell\Core\Models\Taxonomy;
use Illuminate\Validation\ValidationException;

it('creates and updates a site-scoped taxonomy through validated fields', function (): void {
    $site = Site::factory()->create();
    $propertySet = PropertySet::factory()->create();

    $taxonomy = CreateTaxonomyAction::run($site, [
        'key' => 'brands',
        'name' => 'Brands',
        'property_set_id' => $propertySet->id,
    ]);

    UpdateTaxonomyAction::run($taxonomy, ['name' => 'Product brands', 'position' => 2]);

    expect($taxonomy->fresh()?->name)->toBe('Product brands')
        ->and($taxonomy->fresh()?->site_id)->toBe($site->id)
        ->and($taxonomy->fresh()?->position)->toBe(2);
});

it('rejects duplicate taxonomy keys within a site', function (): void {
    $site = Site::factory()->create();
    CreateTaxonomyAction::run($site, ['key' => 'brands', 'name' => 'Brands']);

    expect(fn (): Taxonomy => CreateTaxonomyAction::run($site, ['key' => 'brands', 'name' => 'Other']))
        ->toThrow(ValidationException::class);
});

it('creates and updates only custom property sets', function (): void {
    $propertySet = CreatePropertySetAction::run(['key' => 'custom.products', 'name' => 'Products']);

    UpdatePropertySetAction::run($propertySet, ['name' => 'Custom products']);

    expect($propertySet->fresh()?->name)->toBe('Custom products');

    $owned = PropertySet::factory()->create(['owner_package' => 'vendor/package']);

    expect(fn (): PropertySet => UpdatePropertySetAction::run($owned, ['name' => 'Blocked']))
        ->toThrow(ValidationException::class);
});
