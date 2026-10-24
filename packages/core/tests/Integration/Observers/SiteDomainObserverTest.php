<?php

declare(strict_types=1);

use Capell\Core\Actions\SiteDomains\ResolveSiteDomainAction;
use Capell\Core\Enums\CacheEnum;
use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Support\SiteDomains\SiteDomainAddressing;
use Capell\Tests\Fixtures\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

it('flushes site-related caches when site domain changes', function (): void {
    $site = Site::factory()->createOne();
    $domain = SiteDomain::factory()->createOne(['site_id' => $site->id]);

    Cache::driver('array')->forever(CacheEnum::Site->value . '-default-fallback', true);

    $domain->domain = 'example.com';
    $domain->save();

    $registry = Cache::driver('array')->get('capell-core-cache-keys', []);
    expect($registry)->not()->toContain(CacheEnum::Site->value . '-default-fallback');
});

it('does not use a nested savepoint when creating multiple translated sites', function (): void {
    Site::factory()->withTranslations()->createOne();
    Site::factory()->withTranslations()->createOne();

    expect(Site::query()->count())->toBe(2);
});

it('does not create missing site translations when a site domain is deleted', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->createOne();
    $domain = SiteDomain::factory()->language($language)->create(['site_id' => $site->id]);
    $site->translations()->where('language_id', $language->id)->delete();

    expect($site->translations()->where('language_id', $language->id)->exists())->toBeFalse();

    $domain->delete();

    expect($site->translations()->where('language_id', $language->id)->exists())->toBeFalse();
});

it('preserves wildcard schemes when checking a current domain claim', function (string $defaultScheme, string $ownedScheme, string $operation): void {
    config(['capell-frontend.default_scheme' => $defaultScheme]);
    $site = Site::factory()->createOne();
    $foreign = Site::factory()->createOne();
    $domain = SiteDomain::factory()->site($site)->make(['scheme' => null, 'domain' => 'owned.test', 'path' => '/claimed']);
    if ($operation === 'update') {
        $domain->fill(['scheme' => $defaultScheme, 'domain' => 'safe.test'])->save();
        $domain->fill(['scheme' => null, 'domain' => 'owned.test']);
    }

    if ($operation === 'restore') {
        $domain->save();
        $domain->delete();
    }

    SiteDomain::factory()->site($foreign)->create(['scheme' => $ownedScheme, 'domain' => 'owned.test', 'path' => '/']);
    $actor = User::factory()->createOne();
    $actor->assignedSiteIds = collect([$site->id]);

    test()->actingAs($actor);

    expect(fn () => $operation === 'restore' ? $domain->restore() : $domain->save())
        ->toThrow(ValidationException::class);
    expect(SiteDomain::query()->where('site_id', $site->id)->where('domain', 'owned.test')->exists())->toBeFalse();
})->with([['https', 'http'], ['http', 'https']])->with(['create', 'update', 'restore']);

it('protects origins resolved from non-canonical stored claims', function (?string $storedHost, string $storedScheme, string $claimHost, string $operation): void {
    $site = Site::factory()->createOne();
    $foreign = Site::factory()->createOne();
    $domain = SiteDomain::factory()->site($site)->make(['scheme' => 'https', 'domain' => $claimHost, 'path' => '/claimed']);
    if ($operation === 'update') {
        $domain->fill(['domain' => 'safe.test'])->save();
        $domain->fill(['domain' => $claimHost]);
    } elseif ($operation === 'restore') {
        $domain->save();
        $domain->delete();
    }

    $owned = SiteDomain::factory()->site($foreign)->create(['scheme' => 'https', 'domain' => $claimHost, 'path' => '/']);
    // Simulate legacy storage without canonicalising it through model observers.
    SiteDomain::query()->whereKey($owned->id)->update(['scheme' => $storedScheme, 'domain' => $storedHost]);
    $owned->refresh();
    expect($owned->getRawOriginal('scheme'))->toBe($storedScheme)
        ->and($owned->getRawOriginal('domain'))->toBe($storedHost);

    $resolution = ResolveSiteDomainAction::run(
        SiteDomainAddressing::targetFromUrl('https://' . $claimHost . '/claimed'),
        collect([$foreign->load('siteDomains')]),
    );
    expect($resolution?->siteDomain->id)->toBe($owned->id);

    $actor = User::factory()->createOne();
    $actor->assignedSiteIds = collect([$site->id]);

    test()->actingAs($actor);

    expect(fn () => $operation === 'restore' ? $domain->restore() : $domain->save())
        ->toThrow(ValidationException::class);
    expect(SiteDomain::query()->where('site_id', $site->id)->where('domain', $claimHost)->where('path', '/claimed')->exists())->toBeFalse();
    if ($operation === 'update') {
        expect($domain->fresh()->getRawOriginal('domain'))->toBe('safe.test');
    } elseif ($operation === 'restore') {
        expect($domain->fresh()->trashed())->toBeTrue();
    }

    expect($owned->fresh()->getRawOriginal('domain'))->toBe($storedHost)
        ->and($owned->fresh()->getRawOriginal('scheme'))->toBe($storedScheme);
})->with([
    'stored trailing dot' => ['owned.test.', 'https', 'owned.test'],
    'stored host case' => ['OWNED.TEST', 'https', 'owned.test'],
    'stored scheme case' => ['owned.test', 'HTTPS', 'owned.test'],
    'stored whitespace' => [' Owned.Test. ', ' HTTPS ', 'owned.test'],
    'stored IPv6 spelling' => ['[2001:0db8:0:0:0:0:0:1]', 'HTTPS', '[2001:db8::1]'],
    'stored wildcard host and scheme case' => [null, 'HTTPS', 'owned.test'],
])->with(['create', 'update', 'restore']);
