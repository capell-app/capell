<?php

declare(strict_types=1);

use Capell\Admin\Actions\Upgrade\SendUpgradeNotificationEmailAction;
use Capell\Admin\Notifications\UpgradeSummaryNotification;
use Capell\Admin\Tests\Support\MarketplaceUpdateAdvisorySnapshotTable;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    MarketplaceUpdateAdvisorySnapshotTable::ensure(truncate: true);
    config([
        'capell-admin.upgrades.notifications.enabled' => true,
        'capell-admin.upgrades.notifications.emails' => ['admin@example.com'],
    ]);
});

it('sends the upgrade summary to configured admin emails when updates exist', function (): void {
    Notification::fake();

    DB::table('marketplace_update_advisory_snapshots')->insert([
        'source' => 'capell-api',
        'checked_at' => now(),
        'capell_version' => '4.2.0',
        'updates' => json_encode([
            [
                'notice_id' => 'capell-4-3-0',
                'composer_name' => 'capell-app/capell',
                'installed_version' => '4.2.0',
                'recommended_version' => '4.3.0',
                'type' => 'feature',
                'severity' => 'low',
            ],
        ], JSON_THROW_ON_ERROR),
        'advisories' => json_encode([], JSON_THROW_ON_ERROR),
        'metadata' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(SendUpgradeNotificationEmailAction::run())->toBe(1);

    Notification::assertSentOnDemand(
        UpgradeSummaryNotification::class,
        fn (UpgradeSummaryNotification $notification, array $channels, AnonymousNotifiable $notifiable): bool => $channels === ['mail']
            && $notifiable->routeNotificationFor('mail') === 'admin@example.com',
    );
});

it('does not send when there are no updates or advisories', function (): void {
    Notification::fake();

    expect(SendUpgradeNotificationEmailAction::run())->toBe(0);

    Notification::assertNothingSent();
});

it('does not send when notifications are disabled', function (): void {
    Notification::fake();
    config(['capell-admin.upgrades.notifications.enabled' => false]);

    expect(SendUpgradeNotificationEmailAction::run())->toBe(0);

    Notification::assertNothingSent();
});
