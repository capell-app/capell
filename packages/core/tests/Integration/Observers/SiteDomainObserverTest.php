<?php

declare(strict_types=1);

use Capell\Core\Enums\CacheEnum;
use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
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
