<?php

declare(strict_types=1);

use Capell\Admin\Actions\SetupSiteLanguageAction;
use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;

it('sets up a site language idempotently', function (): void {
    $english = Language::factory()->createOne(['code' => 'en']);
    $french = Language::factory()->createOne(['code' => 'fr']);
    $site = Site::factory()->language($english)->withTranslations($english)->create();

    SiteDomain::factory()->site($site)->language($english)->create([
        'path' => null,
    ]);

    SetupSiteLanguageAction::run($site, $french);
    SetupSiteLanguageAction::run($site, $french);

    expect($site->translations()->where('language_id', $french->getKey())->count())
        ->toBe(1)
        ->and($site->siteDomains()->where('language_id', $french->getKey())->count())
        ->toBe(1);
});
