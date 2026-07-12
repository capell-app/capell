<?php

declare(strict_types=1);

use Capell\Marketplace\Data\ExtensionListingData;
use Capell\Marketplace\Services\VersionCompatibilityChecker;

it('marks installed platform constraints as compatible or incompatible', function (): void {
    $checker = resolve(VersionCompatibilityChecker::class);

    $compatibleListing = marketplaceCompatibilityListing([
        'laravel_version_constraint' => '*',
    ]);
    $incompatibleListing = marketplaceCompatibilityListing([
        'laravel_version_constraint' => '999999.0.0',
        'filament_version_constraint' => '999999.0.0',
    ]);

    expect($checker->isCompatible($compatibleListing))->toBeTrue()
        ->and($checker->compatibilityDetails($compatibleListing)['laravel'])->toBe('ok')
        ->and($checker->isCompatible($incompatibleListing))->toBeFalse()
        ->and($checker->compatibilityDetails($incompatibleListing))->toMatchArray([
            'laravel' => 'incompatible',
            'filament' => 'incompatible',
        ]);
});

it('treats missing constraints and unavailable package versions as non-blocking', function (): void {
    $listing = marketplaceCompatibilityListing([
        'capell_version_constraint' => '^0.0',
        'livewire_version_constraint' => null,
    ]);

    $details = resolve(VersionCompatibilityChecker::class)->compatibilityDetails($listing);

    expect($details)->toHaveKeys(['capell', 'laravel', 'filament', 'livewire'])
        ->and($details['livewire'])->toBe('ok');
});

/**
 * @param  array<string, mixed>  $overrides
 */
function marketplaceCompatibilityListing(array $overrides = []): ExtensionListingData
{
    return ExtensionListingData::fromApiResponse([
        'slug' => 'seo-suite',
        'name' => 'SEO Suite',
        'composer_name' => 'capell-app/seo-suite',
        'kind' => 'tool',
        'price_cents' => 0,
        'is_paid' => false,
        ...$overrides,
    ]);
}
