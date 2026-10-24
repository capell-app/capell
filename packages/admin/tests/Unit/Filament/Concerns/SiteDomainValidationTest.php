<?php

declare(strict_types=1);

use Capell\Admin\Tests\Fixtures\Autoload\SiteDomainValidationHarness;
use Capell\Core\Actions\SiteDomains\FindInaccessibleSiteDomainClaimAction;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Tests\Fixtures\Models\User;
use Capell\Tests\Support\FilamentNotificationSnapshots;
use Illuminate\Validation\ValidationException;

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
    $notification = FilamentNotificationSnapshots::pull()->reverse()->firstOrFail();
    expect($notification->getTitle())->not->toContain('Private foreign site')
        ->and($notification->getActions())->toBe([]);
});

it('rejects a scoped claim over a foreign origin regardless of mount path', function (?string $host, ?string $path, string $claimHost, string $claimPath): void {
    $assigned = Site::factory()->create();
    $foreign = Site::factory()->create();
    SiteDomain::factory()->create(['site_id' => $foreign->id, 'domain' => $host, 'scheme' => 'https', 'path' => $path]);
    $actor = User::factory()->create();
    $actor->assignedSiteIds = collect([$assigned->id]);

    test()->actingAs($actor);

    expect(SiteDomainValidationHarness::validateExists(['scheme' => 'https', 'host' => $claimHost, 'path' => $claimPath]))->toBeFalse();
})->with([
    ['foreign.test', null, 'foreign.test', '/claimed'],
    ['foreign.test', null, 'FOREIGN.TEST.', '/claimed'],
    ['foreign.test', '/existing', 'foreign.test', '/other'],
    [null, null, 'another.test', '/claimed'],
    [null, '/existing', 'another.test', '/other'],
]);

it('retains global administrators ability to partition an origin across sites', function (): void {
    test()->actingAsAdmin();
    $foreign = Site::factory()->create();
    SiteDomain::factory()->create(['site_id' => $foreign->id, 'domain' => 'shared.test', 'scheme' => 'https', 'path' => '/first']);

    expect(SiteDomainValidationHarness::validateExists(['scheme' => 'https', 'host' => 'shared.test', 'path' => '/second']))->toBeTrue();
});

it('enforces foreign origin ownership when saving directly and restoring a domain', function (): void {
    $assigned = Site::factory()->create();
    $foreign = Site::factory()->create();
    SiteDomain::factory()->create(['site_id' => $foreign->id, 'domain' => 'owned.test', 'scheme' => 'https', 'path' => null]);
    $domain = SiteDomain::factory()->create(['site_id' => $assigned->id, 'domain' => 'owned.test', 'scheme' => 'https', 'path' => '/approved']);
    $domain->delete();

    $actor = User::factory()->create();
    $actor->assignedSiteIds = collect([$assigned->id]);

    test()->actingAs($actor);

    expect(fn () => $domain->restore())->toThrow(ValidationException::class);
    expect($domain->fresh()->trashed())->toBeTrue();
    expect(fn () => SiteDomain::factory()->create(['site_id' => $assigned->id, 'domain' => 'owned.test', 'scheme' => 'https', 'path' => '/injected']))
        ->toThrow(ValidationException::class);
    expect(SiteDomain::query()->where('site_id', $assigned->id)->where('path', '/injected')->exists())->toBeFalse();
});

it('allows different origins by scheme or non-default port and domains already owned by the actor', function (): void {
    $assigned = Site::factory()->create();
    $foreign = Site::factory()->create();
    SiteDomain::factory()->create(['site_id' => $foreign->id, 'domain' => 'owned.test', 'scheme' => 'https', 'path' => null]);
    SiteDomain::factory()->create(['site_id' => $assigned->id, 'domain' => 'local.test', 'scheme' => 'https', 'path' => null]);
    $actor = User::factory()->create();
    $actor->assignedSiteIds = collect([$assigned->id]);

    test()->actingAs($actor);

    foreach ([['scheme' => 'http', 'host' => 'owned.test'], ['scheme' => 'https', 'host' => 'owned.test', 'port' => 8443], ['scheme' => 'https', 'host' => 'local.test']] as $parts) {
        expect(FindInaccessibleSiteDomainClaimAction::run($actor, $parts))->toBeNull();
    }
});
