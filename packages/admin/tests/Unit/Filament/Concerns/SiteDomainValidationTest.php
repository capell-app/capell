<?php

declare(strict_types=1);

use Capell\Admin\Tests\Fixtures\Autoload\SiteDomainValidationHarness;
use Capell\Core\Actions\SiteDomains\FindInaccessibleSiteDomainClaimAction;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Tests\Fixtures\Models\User;
use Capell\Tests\Support\FilamentNotificationSnapshots;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
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

it('protects global domain uniqueness without revealing foreign site details', function (?string $domain, string $claimPath): void {
    $assigned = Site::factory()->create();
    $foreign = Site::factory()->create(['name' => 'Private foreign site']);
    SiteDomain::factory()->create(['site_id' => $foreign->getKey(), 'domain' => $domain, 'scheme' => 'https', 'path' => '/private-foreign-mount']);
    $actor = User::factory()->create();
    $actor->assignedSiteIds = collect([(int) $assigned->getKey()]);

    test()->actingAs($actor);

    expect(SiteDomainValidationHarness::validateExists(['scheme' => 'https', 'host' => 'owned.test', 'path' => $claimPath]))->toBeFalse();
    $notification = FilamentNotificationSnapshots::pull()->reverse()->firstOrFail();
    expect($notification->getTitle())->not->toContain('Private foreign site')
        ->and($notification->getBody())->toBeNull()
        ->and($notification->getActions())->toBe([]);
})->with([
    'same mount' => ['owned.test', '/private-foreign-mount'],
    'different mount' => ['owned.test', '/attempt'],
    'fallback domain' => [null, '/attempt'],
]);

it('includes conflict details when the actor can access the owning site', function (bool $global): void {
    $site = Site::factory()->create(['name' => 'Accessible site']);
    $domain = SiteDomain::factory()->create(['site_id' => $site->getKey(), 'domain' => 'owned.test', 'scheme' => 'https', 'path' => '/accessible-mount']);

    if ($global) {
        test()->actingAsAdmin();
    } else {
        $actor = User::factory()->create();
        $actor->assignedSiteIds = collect([(int) $site->getKey()]);
        test()->actingAs($actor);
    }

    expect(SiteDomainValidationHarness::validateExists(['scheme' => 'https', 'host' => 'owned.test', 'path' => '/accessible-mount']))->toBeFalse();

    $notification = FilamentNotificationSnapshots::pull()->reverse()->firstOrFail();
    expect($notification->getTitle())->toBe(__('capell-admin::message.site_domain_not_unique', ['name' => $site->name]))
        ->and($notification->getBody())->toBe($domain->full_url)
        ->and($notification->getActions())->toHaveCount(1)
        ->and(collect($notification->getActions())->map(static fn (Action|ActionGroup $action): ?string => $action instanceof Action ? $action->getName() : null)->all())->toBe(['editSite']);
})->with([
    'assigned actor' => [false],
    'global administrator' => [true],
]);

it('rejects a scoped claim over a foreign origin regardless of mount path', function (?string $host, ?string $path, string $claimHost, string $claimPath, string $storedScheme): void {
    $assigned = Site::factory()->create();
    $foreign = Site::factory()->create();
    $domain = SiteDomain::factory()->create(['site_id' => $foreign->id, 'domain' => $host, 'scheme' => 'https', 'path' => $path]);
    SiteDomain::query()->whereKey($domain->id)->update(['domain' => $host, 'scheme' => $storedScheme]);
    $actor = User::factory()->create();
    $actor->assignedSiteIds = collect([$assigned->id]);

    test()->actingAs($actor);

    expect(SiteDomainValidationHarness::validateExists(['scheme' => 'https', 'host' => $claimHost, 'path' => $claimPath]))->toBeFalse();
})->with([
    ['foreign.test', null, 'foreign.test', '/claimed', 'https'],
    ['foreign.test', null, 'FOREIGN.TEST.', '/claimed', 'https'],
    ['foreign.test', '/existing', 'foreign.test', '/other', 'https'],
    [null, null, 'another.test', '/claimed', 'https'],
    [null, '/existing', 'another.test', '/other', 'https'],
    ['FOREIGN.TEST.', '/', 'foreign.test', '/claimed', 'https'],
    ['foreign.test', '/', 'foreign.test', '/claimed', 'HTTPS'],
    [' Foreign.Test. ', '/existing', 'foreign.test', '/other', ' HTTPS '],
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

it('allows different origins by scheme or non-default port and domains already owned by the actor despite legacy storage', function (): void {
    $assigned = Site::factory()->create();
    $foreign = Site::factory()->create();
    $domain = SiteDomain::factory()->create(['site_id' => $foreign->id, 'domain' => 'owned.test', 'scheme' => 'https', 'path' => null]);
    SiteDomain::query()->whereKey($domain->id)->update(['domain' => 'OWNED.TEST.', 'scheme' => 'HTTPS']);
    SiteDomain::factory()->create(['site_id' => $assigned->id, 'domain' => 'local.test', 'scheme' => 'https', 'path' => null]);
    $actor = User::factory()->create();
    $actor->assignedSiteIds = collect([$assigned->id]);

    test()->actingAs($actor);

    foreach ([['scheme' => 'http', 'host' => 'owned.test'], ['scheme' => 'https', 'host' => 'owned.test', 'port' => 8443], ['scheme' => 'https', 'host' => 'local.test']] as $parts) {
        expect(FindInaccessibleSiteDomainClaimAction::run($actor, $parts))->toBeNull();
    }
});
