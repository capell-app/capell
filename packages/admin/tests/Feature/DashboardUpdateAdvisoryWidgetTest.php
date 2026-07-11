<?php

declare(strict_types=1);

use Capell\Admin\Filament\Widgets\Dashboard\UpdateAdvisoryFilamentWidget;
use Capell\Admin\Tests\Support\MarketplaceUpdateAdvisorySnapshotTable;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(CreatesAdminUser::class);

it('shows critical security advisories on the dashboard Filament widget', function (): void {
    MarketplaceUpdateAdvisorySnapshotTable::ensure();
    test()->actingAsAdmin();

    DB::table('marketplace_update_advisory_snapshots')->insert([
        'source' => 'heartbeat',
        'checked_at' => now(),
        'advisories' => json_encode([
            [
                'notice_id' => 'CAPELL-SA-2026-001',
                'type' => 'security',
                'severity' => 'critical',
                'title' => 'Critical blog editor patch',
                'summary' => 'Upgrade the blog editor package to close the editor bypass.',
            ],
        ], JSON_THROW_ON_ERROR),
        'updates' => json_encode([], JSON_THROW_ON_ERROR),
        'metadata' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Livewire::test(UpdateAdvisoryFilamentWidget::class)
        ->assertOk()
        ->assertSee(__('capell-admin::dashboard.update_advisory_title'))
        ->assertSee('Critical blog editor patch')
        ->assertSee('Upgrade the blog editor package to close the editor bypass.');
});

it('does not show normal package updates as danger dashboard notices', function (): void {
    MarketplaceUpdateAdvisorySnapshotTable::ensure();
    test()->actingAsAdmin();

    DB::table('marketplace_update_advisory_snapshots')->insert([
        'source' => 'heartbeat',
        'checked_at' => now(),
        'advisories' => json_encode([], JSON_THROW_ON_ERROR),
        'updates' => json_encode([
            [
                'notice_id' => 'update-capell-app-blog-1.5.0',
                'type' => 'update',
                'severity' => 'low',
                'title' => 'Blog 1.5.0 is available',
            ],
        ], JSON_THROW_ON_ERROR),
        'metadata' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(UpdateAdvisoryFilamentWidget::canView())->toBeFalse();
    expect(UpdateAdvisoryFilamentWidget::criticalSecurityAdvisories())->toBe([]);
});
