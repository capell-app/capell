<?php

declare(strict_types=1);

use Capell\Admin\Actions\CheckSiteLanguagesMissingDomainsAction;
use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Models\Translation;
use Illuminate\Support\Collection;

it('detects languages missing domains', function (): void {
    $site = Site::factory()->createOne();
    $langA = Language::factory()->createOne();
    $langB = Language::factory()->createOne();

    Translation::factory()
        ->state(['translatable_id' => $site->id, 'translatable_type' => 'site'])
        ->forEachSequence(
            ['language_id' => $langA->id],
            ['language_id' => $langB->id],
        )
        ->create();

    SiteDomain::factory()->site($site)->create(['site_id' => $site->id, 'language_id' => $langA->id]);
    $missing = CheckSiteLanguagesMissingDomainsAction::run($site);

    expect($missing)->toBeInstanceOf(Collection::class);

    expect($missing->pluck('id'))->toContain($langB->id);
});

it('returns empty when all languages have domains', function (): void {
    $site = Site::factory()->createOne();
    $langA = Language::factory()->createOne();

    SiteDomain::factory()->createOne(['site_id' => $site->id, 'language_id' => $langA->id]);

    $missing = CheckSiteLanguagesMissingDomainsAction::run($site);

    expect($missing)->toBeInstanceOf(Collection::class)
        ->toHaveCount(0);
});
