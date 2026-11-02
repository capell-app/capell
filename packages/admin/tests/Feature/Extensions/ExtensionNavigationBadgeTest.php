<?php

declare(strict_types=1);

use Capell\Admin\Filament\Pages\ExtensionsPage;
use Capell\Admin\Support\Extensions\ExtensionNavigationBadgeCounts;
use Capell\Core\Enums\ExtensionStatusEnum;
use Capell\Core\Models\CapellExtension;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Support\Facades\Cache;

uses(CreatesAdminUser::class)->group('extension');

function countingBadgeCounts(): object
{
    $counts = new class extends ExtensionNavigationBadgeCounts
    {
        public int $summaryBuilds = 0;

        #[Override]
        protected function build(): array
        {
            $this->summaryBuilds++;

            return ['unhealthy' => 2, 'blocked' => 1];
        }
    };
    app()->instance(ExtensionNavigationBadgeCounts::class, $counts);

    return $counts;
}

beforeEach(function (): void {
    Cache::flush();
    test()->actingAsAdmin();
});

it('does not rebuild the extension summary for repeated badge reads', function (): void {
    $counts = countingBadgeCounts();

    expect(ExtensionsPage::getNavigationBadge())->toBe('2')
        ->and(ExtensionsPage::getNavigationBadgeColor())->toBe('danger')
        ->and($counts->summaryBuilds)->toBe(1);

    // A fresh instance models the next request: the count still comes from cache.
    $next = countingBadgeCounts();
    ExtensionsPage::getNavigationBadge();

    expect($next->summaryBuilds)->toBe(0);
});

it('rebuilds the badge counts only after extensions change', function (): void {
    $counts = countingBadgeCounts();
    ExtensionsPage::getNavigationBadge();

    CapellExtension::query()->create([
        'composer_name' => 'vendor/badge-demo', 'name' => 'Badge demo', 'version' => '1.0.0',
        'status' => ExtensionStatusEnum::Enabled, 'installed_at' => now(),
    ]);
    $afterChange = countingBadgeCounts();
    ExtensionsPage::getNavigationBadge();
    ExtensionsPage::getNavigationBadge();

    expect($counts->summaryBuilds)->toBe(1)
        ->and($afterChange->summaryBuilds)->toBe(1);
});
