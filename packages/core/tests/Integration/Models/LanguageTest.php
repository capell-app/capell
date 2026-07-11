<?php

declare(strict_types=1);

use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;

it('has many sites through site domains', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->createOne();
    SiteDomain::factory()->createOne(['language_id' => $language->id, 'site_id' => $site->id]);

    expect($language->sites)
        ->toHaveCount(1)
        ->and($language->sites->first()->id)->toBe($site->id)
        ->and($language->sites->first())->toBeInstanceOf(Site::class);
});

it('returns empty collection when no sites are related', function (): void {
    $language = Language::factory()->createOne();
    expect($language->sites)->toHaveCount(0);
});

it('returns all related sites', function (): void {
    $language = Language::factory()->createOne();
    $sites = Site::factory()->count(3)->create();
    foreach ($sites as $site) {
        SiteDomain::factory()->createOne(['language_id' => $language->id, 'site_id' => $site->id]);
    }

    expect($language->sites)->toHaveCount(3);
    foreach ($language->sites as $site) {
        expect($site)->toBeInstanceOf(Site::class);
    }
});

it('returns ordered language options locales and de-duplicated site coverage', function (): void {
    CapellCore::flushCache();

    $english = Language::factory()->createOne([
        'name' => 'English',
        'code' => 'en',
        'locale' => 'en_GB',
        'order' => 1,
        'default' => true,
    ]);
    $french = Language::factory()->createOne([
        'name' => 'French',
        'code' => 'fr',
        'locale' => 'fr_FR',
        'order' => 2,
        'default' => false,
    ]);

    $primarySite = Site::factory()->language($english)->createOne();
    $secondarySite = Site::factory()->language($french)->createOne();
    SiteDomain::factory()->createOne(['language_id' => $english->id, 'site_id' => $primarySite->id]);
    SiteDomain::factory()->createOne(['language_id' => $english->id, 'site_id' => $secondarySite->id]);

    expect(Language::getLanguageLocales())->toBe(['en', 'fr'])
        ->and(Language::getOptions('code', 'name')->all())->toBe([
            'en' => 'English',
            'fr' => 'French',
        ])
        ->and($english->allSites()->pluck('id')->all())->toEqualCanonicalizing([
            $primarySite->id,
            $secondarySite->id,
        ])
        ->and(Language::query()->ordered('desc')->pluck('code')->all())->toBe(['fr', 'en'])
        ->and($english->getCasts())->toMatchArray([
            'meta' => 'json',
            'admin' => 'json',
            'default' => 'boolean',
            'status' => 'boolean',
        ]);
});
