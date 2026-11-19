<?php

declare(strict_types=1);

use Capell\Admin\Actions\Languages\CountSitesUsingLanguageAction;
use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Tests\Support\Concerns\CreatesAdminUser;

uses(CreatesAdminUser::class);

it('counts a site once however many domains it has in the language and includes sites using it directly', function (): void {
    test()->actingAsAdmin();

    $language = Language::factory()->createOne();
    $multiDomain = Site::factory()->createOne();
    SiteDomain::factory()->count(3)->create(['site_id' => $multiDomain->getKey(), 'language_id' => $language->getKey()]);
    Site::factory()->language($language)->createOne();

    expect(CountSitesUsingLanguageAction::run($language))->toBe(2);
});
