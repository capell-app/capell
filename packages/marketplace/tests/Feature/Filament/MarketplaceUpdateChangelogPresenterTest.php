<?php

declare(strict_types=1);

use Capell\Core\Enums\ExtensionReleaseKindEnum;
use Capell\Marketplace\Data\ExtensionDetailData;
use Capell\Marketplace\Filament\Support\MarketplaceUpdateChangelogPresenter;

function detailWithVersionHistory(array $versionHistory): ExtensionDetailData
{
    return ExtensionDetailData::fromApiResponse([
        'slug' => 'seo-suite',
        'name' => 'SEO Suite',
        'composer_name' => 'capell-app/seo-suite',
        'latest_version' => '2.4.0',
        'version_history' => $versionHistory,
    ]);
}

function changelog(): MarketplaceUpdateChangelogPresenter
{
    return resolve(MarketplaceUpdateChangelogPresenter::class);
}

it('shows only releases newer than the installed version, newest first', function (): void {
    $detail = detailWithVersionHistory([
        ['version' => '2.1.0', 'notes' => 'Old news'],
        ['version' => '2.4.0', 'notes' => 'Adds sitemap priorities'],
        ['version' => '2.2.0', 'notes' => 'Fixes canonical tags'],
    ]);

    $entries = changelog()->entriesSince($detail, '2.1.0');

    expect(array_column($entries, 'version'))->toBe(['2.4.0', '2.2.0'])
        ->and($entries[0]['notes'])->toBe('Adds sitemap priorities');
});

it('classifies how big each offered release is', function (): void {
    $detail = detailWithVersionHistory([
        ['version' => '2.1.1', 'notes' => ''],
        ['version' => '3.0.0', 'notes' => ''],
    ]);

    $entries = changelog()->entriesSince($detail, '2.1.0');

    expect(array_column($entries, 'kind'))
        ->toBe([ExtensionReleaseKindEnum::Major->value, ExtensionReleaseKindEnum::Patch->value]);
});

it('reads release notes under whichever key the marketplace used', function (): void {
    $detail = detailWithVersionHistory([
        ['version' => '2.2.0', 'changelog' => 'From changelog'],
        ['version' => '2.3.0', 'body' => 'From body'],
    ]);

    $entries = changelog()->entriesSince($detail, '2.1.0');

    expect(array_column($entries, 'notes'))->toBe(['From body', 'From changelog']);
});

it('bounds the changelog so a long-neglected extension does not produce an unreadable modal', function (): void {
    $releases = [];

    foreach (range(1, MarketplaceUpdateChangelogPresenter::MAXIMUM_ENTRIES + 5) as $patch) {
        $releases[] = ['version' => '2.1.' . $patch, 'notes' => 'Patch ' . $patch];
    }

    $entries = changelog()->entriesSince(detailWithVersionHistory($releases), '2.1.0');

    expect($entries)->toHaveCount(MarketplaceUpdateChangelogPresenter::MAXIMUM_ENTRIES)
        ->and($entries[0]['version'])->toBe('2.1.' . (MarketplaceUpdateChangelogPresenter::MAXIMUM_ENTRIES + 5));
});

it('returns nothing when the extension published no version history', function (): void {
    expect(changelog()->entriesSince(detailWithVersionHistory([]), '2.1.0'))->toBe([]);
});

it('skips history entries that do not name a version', function (): void {
    $detail = detailWithVersionHistory([
        ['notes' => 'A release with no version'],
        ['version' => '2.2.0', 'notes' => 'Real'],
    ]);

    expect(changelog()->entriesSince($detail, '2.1.0'))->toHaveCount(1);
});
