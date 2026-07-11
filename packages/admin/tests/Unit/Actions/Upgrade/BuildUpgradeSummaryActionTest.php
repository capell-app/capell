<?php

declare(strict_types=1);

use Capell\Admin\Actions\Upgrade\BuildUpgradeSummaryAction;
use Capell\Admin\Tests\Support\MarketplaceUpdateAdvisorySnapshotTable;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    MarketplaceUpdateAdvisorySnapshotTable::ensure(truncate: true);
});

it('builds a current summary when no snapshot exists', function (): void {
    $summary = BuildUpgradeSummaryAction::run();

    expect($summary->maxVersionsBehind)->toBe(0)
        ->and($summary->navigationBadge)->toBeNull()
        ->and($summary->navigationBadgeColor)->toBe('success')
        ->and($summary->hasNotifications())->toBeFalse();
});

it('counts the furthest package releases behind', function (): void {
    DB::table('marketplace_update_advisory_snapshots')->insert([
        'source' => 'capell-api',
        'checked_at' => now(),
        'capell_version' => '4.2.0',
        'updates' => json_encode([
            [
                'notice_id' => 'capell-4-5-0',
                'composer_name' => 'capell-app/capell',
                'installed_version' => '4.2.0',
                'recommended_version' => '4.5.0',
                'severity' => 'low',
                'type' => 'feature',
            ],
            [
                'notice_id' => 'blog-1-5-0',
                'composer_name' => 'capell-app/blog',
                'installed_version' => '1.4.0',
                'recommended_version' => '1.5.0',
                'severity' => 'low',
                'type' => 'feature',
            ],
        ], JSON_THROW_ON_ERROR),
        'advisories' => json_encode([], JSON_THROW_ON_ERROR),
        'metadata' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    config(['capell-admin.upgrades.danger_threshold' => 3]);

    $summary = BuildUpgradeSummaryAction::run();

    expect($summary->maxVersionsBehind)->toBe(3)
        ->and($summary->navigationBadge)->toBe('3 behind')
        ->and($summary->navigationBadgeColor)->toBe('danger')
        ->and($summary->updateCount)->toBe(2)
        ->and($summary->securityCount)->toBe(0)
        ->and($summary->hasNotifications())->toBeTrue();
});

it('uses danger colour for high security advisories even below threshold', function (): void {
    DB::table('marketplace_update_advisory_snapshots')->insert([
        'source' => 'capell-api',
        'checked_at' => now(),
        'capell_version' => '4.2.0',
        'updates' => json_encode([], JSON_THROW_ON_ERROR),
        'advisories' => json_encode([
            [
                'notice_id' => 'CAPELL-SA-2026-001',
                'type' => 'security',
                'severity' => 'high',
                'title' => 'Security patch available',
                'affected_packages' => [
                    [
                        'composer_name' => 'capell-app/capell',
                        'installed_version' => '4.2.0',
                        'fixed_version' => '4.3.0',
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR),
        'metadata' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    config(['capell-admin.upgrades.danger_threshold' => 3]);

    $summary = BuildUpgradeSummaryAction::run();

    expect($summary->maxVersionsBehind)->toBe(1)
        ->and($summary->navigationBadge)->toBe('1 behind')
        ->and($summary->navigationBadgeColor)->toBe('danger')
        ->and($summary->securityCount)->toBe(1);
});
