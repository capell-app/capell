<?php

declare(strict_types=1);

use Capell\Admin\Filament\Pages\UpgradePage;
use Capell\Admin\Tests\Support\MarketplaceUpdateAdvisorySnapshotTable;
use Capell\Marketplace\Actions\CheckForUpdatesAction;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

use function Pest\Laravel\get;

use Spatie\Permission\Models\Permission;

uses(CreatesAdminUser::class);

it('shows update and advisory notices in the upgrade center', function (): void {
    MarketplaceUpdateAdvisorySnapshotTable::ensure();
    ensureUpdateNoticeDismissalTable();
    Permission::create(['name' => 'View:UpgradePage', 'guard_name' => 'web']);
    test()->actingAsAdmin();
    test()->authenticatedUser()->givePermissionTo('View:UpgradePage');

    DB::table('marketplace_update_advisory_snapshots')->insert([
        'source' => 'heartbeat',
        'checked_at' => now(),
        'capell_version' => '4.2.0',
        'updates' => json_encode([
            [
                'composer_name' => 'capell-app/blog',
                'title' => 'Blog 1.5.0 is available',
                'summary' => 'Blog 1.5.0 contains editor fixes and moderation improvements.',
                'installed_version' => '1.4.0',
                'recommended_version' => '1.5.0',
                'severity' => 'low',
                'release_notes_url' => 'https://capell.test/releases/blog-1-5-0',
            ],
        ], JSON_THROW_ON_ERROR),
        'advisories' => json_encode([
            [
                'id' => 'CAPELL-2026-001',
                'type' => 'security',
                'severity' => 'critical',
                'title' => 'Critical blog editor patch',
                'summary' => 'Upgrade the blog editor package to close the editor bypass.',
                'affected_packages' => [
                    [
                        'composer_name' => 'capell-app/blog',
                        'installed_version' => '1.4.0',
                        'fixed_version' => '1.5.0',
                    ],
                ],
                'release_notes_url' => 'https://capell.test/releases/blog-security-patch',
                'upgrade_guide_url' => 'https://capell.test/guides/blog-security-patch',
            ],
            [
                'id' => 'CAPELL-BUG-2026-002',
                'type' => 'bug',
                'severity' => 'medium',
                'title' => 'Blog image upload patch',
                'summary' => 'Fixes failed uploads for larger blog images.',
                'affected_packages' => [
                    [
                        'composer_name' => 'capell-app/blog',
                        'installed_version' => '1.4.0',
                        'fixed_version' => '1.5.0',
                    ],
                ],
                'release_notes_url' => 'https://capell.test/releases/blog-upload-patch',
            ],
        ], JSON_THROW_ON_ERROR),
        'metadata' => json_encode(['response_id' => 'heartbeat-response-123'], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    get(UpgradePage::getUrl())
        ->assertOk()
        ->assertSeeText(__('capell-admin::button.check_now'))
        ->assertSeeText(__('capell-admin::generic.updates_to_review'))
        ->assertSeeText('Critical blog editor patch')
        ->assertSeeText('Upgrade the blog editor package to close the editor bypass.')
        ->assertSeeText('Blog image upload patch')
        ->assertSeeText('Fixes failed uploads for larger blog images.')
        ->assertSeeText('Blog 1.5.0 is available')
        ->assertSeeText('Blog 1.5.0 contains editor fixes and moderation improvements.')
        ->assertSeeText('capell-app/blog')
        ->assertSeeText('1.4.0')
        ->assertSeeText('1.5.0')
        ->assertSeeText(__('capell-admin::generic.estimated_impact'))
        ->assertSeeText(__('capell-admin::generic.release_notes'))
        ->assertSeeText(__('capell-admin::generic.upgrade_guide'));
});

it('dismisses normal update notices without hiding persistent security advisories', function (): void {
    MarketplaceUpdateAdvisorySnapshotTable::ensure();
    ensureUpdateNoticeDismissalTable();
    Permission::create(['name' => 'View:UpgradePage', 'guard_name' => 'web']);
    test()->actingAsAdmin();
    test()->authenticatedUser()->givePermissionTo('View:UpgradePage');

    DB::table('marketplace_update_advisory_snapshots')->insert([
        'source' => 'heartbeat',
        'checked_at' => now(),
        'capell_version' => '4.2.0',
        'updates' => json_encode([
            [
                'notice_id' => 'update-capell-app-blog-1.5.0',
                'composer_name' => 'capell-app/blog',
                'title' => 'Blog 1.5.0 is available',
                'summary' => 'Blog 1.5.0 contains editor fixes.',
                'installed_version' => '1.4.0',
                'recommended_version' => '1.5.0',
                'severity' => 'low',
            ],
        ], JSON_THROW_ON_ERROR),
        'advisories' => json_encode([
            [
                'notice_id' => 'CAPELL-SA-2026-001',
                'type' => 'security',
                'severity' => 'critical',
                'title' => 'Critical blog editor patch',
                'summary' => 'Upgrade the blog editor package.',
            ],
        ], JSON_THROW_ON_ERROR),
        'metadata' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Livewire::test(UpgradePage::class)
        ->assertSee('Blog 1.5.0 is available')
        ->assertSee('Critical blog editor patch')
        ->call('dismissNotice', 'update-capell-app-blog-1.5.0')
        ->assertNotified(__('capell-admin::message.update_notice_dismissed'))
        ->assertDontSee('Blog 1.5.0 is available')
        ->assertSee('Critical blog editor patch');

    expect(DB::table('marketplace_update_notice_dismissals')
        ->where('user_id', auth()->id())
        ->where('notice_id', 'update-capell-app-blog-1.5.0')
        ->exists())->toBeTrue();
});

it('runs the manual update check action', function (): void {
    MarketplaceUpdateAdvisorySnapshotTable::ensure();
    Permission::create(['name' => 'View:UpgradePage', 'guard_name' => 'web']);
    test()->actingAsAdmin();
    test()->authenticatedUser()->givePermissionTo('View:UpgradePage');
    Http::fake([
        config('capell-admin.upgrades.api_url') => Http::response([
            'data' => [
                'capell_version' => '4.2.0',
                'updates' => [],
                'advisories' => [],
                'response_id' => 'manual-check-response',
            ],
        ], 200),
    ]);

    Livewire::test(UpgradePage::class)
        ->callAction('checkForUpdates')
        ->assertNotified(__('capell-admin::message.update_check_complete'));

    expect(DB::table('marketplace_update_advisory_snapshots')
        ->where('source', 'capell-api')
        ->exists())->toBeTrue();
});

it('does not delegate the manual update check to marketplace package code', function (): void {
    MarketplaceUpdateAdvisorySnapshotTable::ensure();
    Permission::create(['name' => 'View:UpgradePage', 'guard_name' => 'web']);
    test()->actingAsAdmin();
    test()->authenticatedUser()->givePermissionTo('View:UpgradePage');
    config(['capell-admin.upgrades.api_enabled' => false]);

    $spy = bindFakeAction(CheckForUpdatesAction::class, false);

    Livewire::test(UpgradePage::class)
        ->callAction('checkForUpdates')
        ->assertNotified(__('capell-admin::message.update_check_complete'));

    expect($spy->called)->toBeFalse()
        ->and(DB::table('marketplace_update_advisory_snapshots')
            ->where('source', 'admin')
            ->exists())->toBeTrue();
});

it('shows the releases behind count in the upgrade navigation badge', function (): void {
    MarketplaceUpdateAdvisorySnapshotTable::ensure();
    Permission::create(['name' => 'View:UpgradePage', 'guard_name' => 'web']);
    test()->actingAsAdmin();
    test()->authenticatedUser()->givePermissionTo('View:UpgradePage');

    DB::table('marketplace_update_advisory_snapshots')->insert([
        'source' => 'capell-api',
        'checked_at' => now()->addMinute(),
        'capell_version' => '4.2.0',
        'updates' => json_encode([
            [
                'notice_id' => 'capell-4-4-0',
                'composer_name' => 'capell-app/capell',
                'installed_version' => '4.2.0',
                'recommended_version' => '4.4.0',
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

    expect(UpgradePage::getNavigationBadge())->toBe('2 behind')
        ->and(UpgradePage::getNavigationBadgeColor())->toBe('warning');
});

it('summarises mixed upgrade notices and derives operator commands', function (): void {
    MarketplaceUpdateAdvisorySnapshotTable::ensure();
    ensureUpdateNoticeDismissalTable();
    Permission::create(['name' => 'View:UpgradePage', 'guard_name' => 'web']);
    test()->actingAsAdmin();
    test()->authenticatedUser()->givePermissionTo('View:UpgradePage');

    DB::table('marketplace_update_advisory_snapshots')->insert([
        'source' => 'capell-api',
        'checked_at' => now(),
        'capell_version' => '4.2.0',
        'updates' => json_encode([
            [
                'notice_id' => 'capell-major',
                'composer_name' => 'capell-app/capell',
                'installed_version' => '4.2.0',
                'recommended_version' => '5.0.0',
                'severity' => 'low',
            ],
            [
                'notice_id' => 'package-feature',
                'package' => 'capell-app/blog',
                'current_version' => '1.4.0',
                'latest_version' => '1.6.0',
                'release_type' => 'feature',
                'affected_packages' => [
                    [
                        'composer_name' => 'capell-app/media',
                        'installed_version' => '2.0.0',
                        'fixed_version' => '2.1.0',
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR),
        'advisories' => json_encode([
            [
                'notice_id' => 'security-high',
                'type' => 'security',
                'severity' => 'high',
                'affected_packages' => [
                    [
                        'composer_name' => 'capell-app/blog',
                        'installed_version' => '1.4.0',
                        'fixed_version' => '1.6.0',
                    ],
                ],
            ],
            [
                'notice_id' => 'bug-medium',
                'type' => 'bug',
                'severity' => 'medium',
                'fixed_versions' => [
                    'capell-app/forms' => '3.2.0',
                    'capell-app/search' => '2.4.1',
                ],
            ],
        ], JSON_THROW_ON_ERROR),
        'metadata' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $page = new UpgradePage;
    $packageFeatureNotice = $page->updateNotices()[1];
    $securityNotice = $page->securityAdvisories()[0];
    $bugNotice = $page->bugAdvisories()[0];

    expect($page->latestAdvisorySnapshot()?->capell_version)->toBe('4.2.0')
        ->and($page->targetCapellVersion())->toBe('5.0.0')
        ->and($page->updateSummaryCounts())->toMatchArray([
            'security' => 1,
            'bugfix' => 1,
            'feature' => 1,
            'major' => 1,
            'package' => 2,
            'total' => 4,
        ])
        ->and($page->noticeComposerNames($packageFeatureNotice))->toBe([
            'capell-app/blog',
            'capell-app/media',
        ])
        ->and($page->noticeComposerNamesLabel($packageFeatureNotice))->toBe('capell-app/blog, capell-app/media')
        ->and($page->noticeComposerCommand($packageFeatureNotice))->toBe('composer update capell-app/blog capell-app/media')
        ->and($page->noticeVersionLine($packageFeatureNotice))->toBe(__('capell-admin::generic.current_to_target_version', [
            'current' => '1.4.0',
            'target' => '1.6.0',
        ]))
        ->and($page->noticeCanBeDismissed($packageFeatureNotice))->toBeTrue()
        ->and($page->noticeCanBeDismissed($securityNotice))->toBeFalse()
        ->and($page->noticeFixedVersionsLabel($bugNotice))->toBe('capell-app/forms: 3.2.0, capell-app/search: 2.4.1')
        ->and($page->noticeImpactLabel($securityNotice))->toBe(__('capell-admin::generic.impact_high'))
        ->and($page->noticeImpactLabel($bugNotice))->toBe(__('capell-admin::generic.impact_medium'))
        ->and($page->manualUpgradeCommand(dryRun: true))->toBe('php artisan capell:upgrade --force --no-clear-cache --dry-run');
});

it('uses safe fallback values for missing or malformed advisory data', function (): void {
    MarketplaceUpdateAdvisorySnapshotTable::ensure();
    ensureUpdateNoticeDismissalTable();
    Permission::create(['name' => 'View:UpgradePage', 'guard_name' => 'web']);
    test()->actingAsAdmin();
    test()->authenticatedUser()->givePermissionTo('View:UpgradePage');

    DB::table('marketplace_update_advisory_snapshots')->insert([
        'source' => 'capell-api',
        'checked_at' => now(),
        'capell_version' => null,
        'updates' => '{invalid',
        'advisories' => json_encode([
            [
                'id' => 'empty-fixed-versions',
                'type' => 'bug',
                'severity' => 'low',
                'fixed_versions' => ['', null],
            ],
        ], JSON_THROW_ON_ERROR),
        'metadata' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $page = new UpgradePage;
    $noticeWithoutPackages = $page->bugAdvisories()[0];

    expect($page->latestAdvisorySnapshot()?->updates)->toBe([])
        ->and($page->updateNotices())->toBe([])
        ->and($page->targetCapellVersion())->toBe(__('capell-admin::generic.no_core_update_target'))
        ->and($page->updateDistanceLabel())->toBe(__('capell-admin::generic.update_distance_unknown'))
        ->and($page->noticeComposerNames($noticeWithoutPackages))->toBe([])
        ->and($page->noticeComposerNamesLabel($noticeWithoutPackages))->toBe(__('capell-admin::generic.unknown'))
        ->and($page->noticeComposerCommand($noticeWithoutPackages))->toBe(__('capell-admin::generic.unknown'))
        ->and($page->noticeInstalledVersion($noticeWithoutPackages))->toBe(__('capell-admin::generic.unknown'))
        ->and($page->noticeRecommendedVersion($noticeWithoutPackages))->toBe(__('capell-admin::generic.unknown'))
        ->and($page->noticeImpactLabel($noticeWithoutPackages))->toBe(__('capell-admin::generic.impact_low'));
});

it('reports a failed dismissal when the requested notice is not in the current snapshot', function (): void {
    MarketplaceUpdateAdvisorySnapshotTable::ensure();
    ensureUpdateNoticeDismissalTable();
    Permission::create(['name' => 'View:UpgradePage', 'guard_name' => 'web']);
    test()->actingAsAdmin();
    test()->authenticatedUser()->givePermissionTo('View:UpgradePage');

    DB::table('marketplace_update_advisory_snapshots')->insert([
        'source' => 'capell-api',
        'checked_at' => now(),
        'capell_version' => '4.2.0',
        'updates' => json_encode([], JSON_THROW_ON_ERROR),
        'advisories' => json_encode([], JSON_THROW_ON_ERROR),
        'metadata' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Livewire::test(UpgradePage::class)
        ->call('dismissNotice', 'missing-notice')
        ->assertNotified(__('capell-admin::message.update_notice_dismiss_failed'));

    expect(DB::table('marketplace_update_notice_dismissals')->count())->toBe(0);
});

function ensureUpdateNoticeDismissalTable(): void
{
    if (! Schema::hasTable('users')) {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
        });
    }

    if (! Schema::hasTable('marketplace_update_notice_dismissals')) {
        Schema::create('marketplace_update_notice_dismissals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('notice_id');
            $table->timestamp('dismissed_until')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'notice_id']);
        });
    }

    DB::table('users')->insertOrIgnore(['id' => auth()->id()]);
}
