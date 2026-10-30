<?php

declare(strict_types=1);

use Capell\Admin\Actions\SetupSiteLanguageAction;
use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Tests\Fixtures\Models\User;
use Illuminate\Validation\ValidationException;

it('sets up a site language idempotently', function (): void {
    $english = Language::factory()->createOne(['code' => 'en']);
    $french = Language::factory()->createOne(['code' => 'fr']);
    $site = Site::factory()->language($english)->withTranslations($english)->create();
    $englishTranslation = $site->translations()->where('language_id', $english->getKey())->firstOrFail();
    $englishTranslation->forceFill([
        'meta' => ['footer_copy' => '<p>Reliable multilingual content</p>'],
    ])->save();

    SetupSiteLanguageAction::run($site, $french);
    SetupSiteLanguageAction::run($site, $french);

    expect($site->translations()->where('language_id', $french->getKey())->count())
        ->toBe(1)
        ->and($site->siteDomains()->where('language_id', $french->getKey())->count())
        ->toBe(1)
        ->and($site->translations()->where('language_id', $french->getKey())->firstOrFail()->meta)
        ->toBe(['footer_copy' => '<p>Reliable multilingual content</p>']);
});

it('mounts a new language route under the existing site path', function (?string $mount, string $expected): void {
    $english = Language::factory()->createOne(['code' => 'en']);
    $french = Language::factory()->createOne(['code' => 'fr']);
    $site = Site::factory()->language($english)->withTranslations($english)->create();
    $site->siteDomains()->firstOrFail()->update(['domain' => 'mounted.example.test', 'scheme' => 'https', 'path' => $mount]);

    SetupSiteLanguageAction::run($site, $french);

    $domain = $site->siteDomains()->where('language_id', $french->getKey())->firstOrFail();

    expect($domain->path)->toBe($expected)
        ->and($domain->domain)->toBe('mounted.example.test')
        ->and($site->siteDomains()->where('language_id', $english->getKey())->firstOrFail()->path)->toBe($mount);
})->with([
    'root site' => [null, '/fr'],
    'mounted site' => ['/showcase', '/showcase/fr'],
    'mounted site with trailing slash' => ['/showcase/', '/showcase/fr'],
    'mount that already ends in the language code' => ['/showcase/en', '/showcase/fr'],
]);

it('rejects a generated language route that another site already claims and leaves no partial setup', function (): void {
    $english = Language::factory()->createOne(['code' => 'en']);
    $french = Language::factory()->createOne(['code' => 'fr']);
    $site = Site::factory()->language($english)->withTranslations($english)->create();
    $site->siteDomains()->firstOrFail()->update(['domain' => 'mounted.example.test', 'scheme' => 'https', 'path' => '/showcase']);
    $other = Site::factory()->language($english)->withTranslations($english)->create();
    $other->siteDomains()->firstOrFail()->update(['domain' => 'mounted.example.test', 'scheme' => 'https', 'path' => '/showcase/fr']);

    expect(fn () => SetupSiteLanguageAction::run($site, $french))->toThrow(ValidationException::class);

    expect($site->translations()->where('language_id', $french->getKey())->exists())->toBeFalse()
        ->and(SiteDomain::query()->where('site_id', $site->getKey())->where('language_id', $french->getKey())->exists())->toBeFalse();
});

/**
 * @return array{site: Site, other: Site, french: Language}
 */
function siteWithForeignClaimedFrenchRoute(): array
{
    $english = Language::factory()->createOne(['code' => 'en']);
    $french = Language::factory()->createOne(['code' => 'fr', 'name' => 'French']);
    $site = Site::factory()->language($english)->withTranslations($english)->create(['name' => 'Editor site']);
    $site->siteDomains()->firstOrFail()->update(['domain' => 'collision.example.test', 'scheme' => 'https', 'path' => '/showcase']);
    $other = Site::factory()->language($english)->withTranslations($english)->create(['name' => 'Confidential owner']);
    $other->siteDomains()->firstOrFail()->update(['domain' => 'collision.example.test', 'scheme' => 'https', 'path' => '/showcase/fr']);

    return ['site' => $site, 'other' => $other, 'french' => $french];
}

it('does not reveal the owning site or its address to an editor without access to that site', function (): void {
    ['site' => $site, 'french' => $french] = siteWithForeignClaimedFrenchRoute();
    $editor = User::factory()->create();
    $editor->assignedSiteIds = collect([(int) $site->getKey()]);

    test()->actingAs($editor);

    try {
        SetupSiteLanguageAction::run($site, $french);
        $this->fail('Expected the colliding route to be rejected.');
    } catch (ValidationException $validationException) {
        $message = implode(' ', $validationException->errors()['path']);
    }

    expect($message)->not->toContain('Confidential owner')
        ->and($message)->not->toContain('collision.example.test')
        ->and($message)->not->toContain('/showcase/fr')
        ->and($message)->toContain('French')
        ->and($message)->toBe(__('capell-admin::message.site_language_route_taken_generic', ['language' => 'French']));
});

it('names the owning site and its address to an editor who can access it', function (bool $global): void {
    ['site' => $site, 'other' => $other, 'french' => $french] = siteWithForeignClaimedFrenchRoute();

    if ($global) {
        test()->actingAsAdmin();
    } else {
        $editor = User::factory()->create();
        $editor->assignedSiteIds = collect([(int) $site->getKey(), (int) $other->getKey()]);
        test()->actingAs($editor);
    }

    try {
        SetupSiteLanguageAction::run($site, $french);
        $this->fail('Expected the colliding route to be rejected.');
    } catch (ValidationException $validationException) {
        $message = implode(' ', $validationException->errors()['path']);
    }

    expect($message)->toContain('Confidential owner')
        ->and($message)->toContain('collision.example.test/showcase/fr');
})->with([
    'assigned to both sites' => [false],
    'global administrator' => [true],
]);
