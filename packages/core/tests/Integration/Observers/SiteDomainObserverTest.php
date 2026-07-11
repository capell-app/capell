<?php

declare(strict_types=1);

use Capell\Core\Enums\CacheEnum;
use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Illuminate\Support\Facades\Cache;

it('flushes site-related caches when site domain changes', function (): void {
    $site = Site::factory()->createOne();
    $domain = SiteDomain::factory()->createOne(['site_id' => $site->id]);

    Cache::driver('array')->forever(CacheEnum::Site->value . '-default-fallback', true);

    $domain->domain = 'example.com';
    $domain->save();

    $registry = Cache::driver('array')->get('capell-core-cache-keys', []);
    expect($registry)->not()->toContain(CacheEnum::Site->value . '-default-fallback');
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
