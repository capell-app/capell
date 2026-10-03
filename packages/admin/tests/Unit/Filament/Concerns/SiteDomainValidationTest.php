<?php

declare(strict_types=1);

use Capell\Admin\Tests\Fixtures\Autoload\SiteDomainValidationHarness;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Tests\Fixtures\Models\User;
use Filament\Notifications\Notification;

it('rejects duplicate null domains with the same scheme and path', function (): void {
    $site = Site::factory()->createOne();

    SiteDomain::factory()->createOne([
        'site_id' => $site->id,
        'domain' => null,
        'scheme' => 'https',
        'path' => '/tenant',
    ]);

    expect(SiteDomainValidationHarness::validateExists([
        'scheme' => 'https',
        'host' => null,
        'path' => '/tenant',
    ]))->toBeFalse();
});

it('allows an explicit host when only a null domain fallback exists', function (): void {
    $site = Site::factory()->createOne();

    SiteDomain::factory()->createOne([
        'site_id' => $site->id,
        'domain' => null,
        'scheme' => 'https',
        'path' => '/tenant',
    ]);

    expect(SiteDomainValidationHarness::validateExists([
        'scheme' => 'https',
        'host' => 'example.test',
        'path' => '/tenant',
    ]))->toBeTrue();
});

it('rejects the app host when a matching null domain fallback exists', function (): void {
    config(['app.url' => 'https://capell.test']);

    $site = Site::factory()->createOne();

    SiteDomain::factory()->createOne([
        'site_id' => $site->id,
        'domain' => null,
        'scheme' => 'https',
        'path' => '/tenant',
    ]);

    expect(SiteDomainValidationHarness::validateExists([
        'scheme' => 'https',
        'host' => 'capell.test',
        'path' => '/tenant',
    ]))->toBeFalse();
});

it('rejects duplicate null schemes with the same host and path', function (): void {
    $site = Site::factory()->createOne();

    SiteDomain::factory()->createOne([
        'site_id' => $site->id,
        'domain' => 'example.test',
        'scheme' => null,
        'path' => '/tenant',
    ]);

    expect(SiteDomainValidationHarness::validateExists([
        'scheme' => null,
        'host' => 'example.test',
        'path' => '/tenant',
    ]))->toBeFalse();
});

it('rejects a specific scheme when a null scheme fallback exists', function (): void {
    $site = Site::factory()->createOne();

    SiteDomain::factory()->createOne([
        'site_id' => $site->id,
        'domain' => 'example.test',
        'scheme' => null,
        'path' => '/tenant',
    ]);

    expect(SiteDomainValidationHarness::validateExists([
        'scheme' => 'https',
        'host' => 'example.test',
        'path' => '/tenant',
    ]))->toBeFalse();
});

it('rejects a null scheme when a specific scheme exists for the same host and path', function (): void {
    $site = Site::factory()->createOne();

    SiteDomain::factory()->createOne([
        'site_id' => $site->id,
        'domain' => 'example.test',
        'scheme' => 'https',
        'path' => '/tenant',
    ]);

    expect(SiteDomainValidationHarness::validateExists([
        'scheme' => null,
        'host' => 'example.test',
        'path' => '/tenant',
    ]))->toBeFalse();
});

it('allows a null scheme when the path differs', function (): void {
    $site = Site::factory()->createOne();

    SiteDomain::factory()->createOne([
        'site_id' => $site->id,
        'domain' => 'example.test',
        'scheme' => 'https',
        'path' => '/tenant',
    ]);

    expect(SiteDomainValidationHarness::validateExists([
        'scheme' => null,
        'host' => 'example.test',
        'path' => '/other',
    ]))->toBeTrue();
});

it('protects global domain uniqueness without revealing a foreign site name or edit link', function (): void {
    $assigned = Site::factory()->create();
    $foreign = Site::factory()->create(['name' => 'Private foreign site']);
    SiteDomain::factory()->create(['site_id' => $foreign->getKey(), 'domain' => 'private.example.test', 'scheme' => 'https', 'path' => '/private']);
    $actor = User::factory()->create();
    $actor->assignedSiteIds = collect([(int) $assigned->getKey()]);

    test()->actingAs($actor);

    expect(SiteDomainValidationHarness::validateExists(['scheme' => 'https', 'host' => 'private.example.test', 'path' => '/private']))->toBeFalse();
    $notifications = session('filament.notifications');
    $notification = Notification::fromArray(end($notifications));
    expect($notification->getTitle())->not->toContain('Private foreign site')
        ->and($notification->getActions())->toBe([]);
});
