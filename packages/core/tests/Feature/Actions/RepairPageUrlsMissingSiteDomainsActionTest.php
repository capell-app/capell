<?php

declare(strict_types=1);

use Capell\Core\Actions\FindPageUrlsMissingSiteDomainsAction;
use Capell\Core\Actions\RepairPageUrlsMissingSiteDomainsAction;
use Capell\Core\Models\Language;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;

/**
 * The state a fresh demo install left behind: the first site only has a root domain for
 * its primary language, while the demo seeded its pages in every language.
 *
 * @return array{languages: array<string, Language>, sites: array<string, Site>}
 */
function seedDemoStateWithMissingSiteDomains(): array
{
    $languages = [];

    foreach (['de', 'en', 'fr'] as $index => $code) {
        $languages[$code] = Language::factory()->create([
            'code' => $code,
            'locale' => $code . '_' . mb_strtoupper($code),
            'default' => $index === 0,
        ]);
    }

    $services = Site::factory()->create(['name' => 'Capell Services', 'language_id' => $languages['de']->getKey()]);
    $laravel = Site::factory()->create(['name' => 'Laravel', 'language_id' => $languages['en']->getKey()]);

    $domain = static fn (Site $site, Language $language, ?string $path, bool $default = false): SiteDomain => SiteDomain::factory()->create([
        'site_id' => $site->getKey(),
        'language_id' => $language->getKey(),
        'scheme' => 'http',
        'domain' => 'localhost',
        'port' => 8000,
        'path' => $path,
        'default' => $default,
        'status' => true,
    ]);

    $domain($services, $languages['de'], null, true);
    $domain($laravel, $languages['en'], '/laravel', true);
    $domain($laravel, $languages['fr'], '/fr/laravel');
    $domain($laravel, $languages['de'], '/de/laravel');

    foreach ([$services, $laravel] as $site) {
        foreach ($languages as $language) {
            foreach (['/about', '/contact', '/team'] as $url) {
                PageUrl::factory()->site($site)->language($language)->createOne(['url' => $url . '-' . $language->code]);
            }
        }
    }

    return ['languages' => $languages, 'sites' => ['services' => $services, 'laravel' => $laravel]];
}

function activeRoutingIdentities(): array
{
    return SiteDomain::query()->where('status', true)->pluck('routing_identity')->all();
}

it('gives every page URL in the demo seed state a site domain without reusing a routing identity', function (): void {
    ['languages' => $languages, 'sites' => $sites] = seedDemoStateWithMissingSiteDomains();

    expect(FindPageUrlsMissingSiteDomainsAction::run())->toHaveCount(6);

    expect(RepairPageUrlsMissingSiteDomainsAction::run())->toBe(2)
        ->and(FindPageUrlsMissingSiteDomainsAction::run())->toBeEmpty()
        ->and(activeRoutingIdentities())->toHaveCount(6)->each->not->toBeNull()
        ->and(array_unique(activeRoutingIdentities()))->toHaveCount(6);

    $services = $sites['services'];

    foreach (['en', 'fr'] as $code) {
        $siteDomain = $services->siteDomains()->where('language_id', $languages[$code]->getKey())->sole();

        // The language is routed inside the site's own address, not on a wildcard host.
        expect($siteDomain->getAttributes()['domain'])->toBe('localhost')
            ->and($siteDomain->path)->toBe('/' . $code)
            ->and($siteDomain->status)->toBeTrue();
    }
});

it('is idempotent once the demo seed state is repaired', function (): void {
    seedDemoStateWithMissingSiteDomains();

    RepairPageUrlsMissingSiteDomainsAction::run();
    $identities = activeRoutingIdentities();

    expect(RepairPageUrlsMissingSiteDomainsAction::run())->toBe(0)
        ->and(activeRoutingIdentities())->toBe($identities)
        ->and(FindPageUrlsMissingSiteDomainsAction::run())->toBeEmpty();
});

it('moves a deleted site domain to a free address when its own address now routes elsewhere', function (): void {
    ['languages' => $languages, 'sites' => $sites] = seedDemoStateWithMissingSiteDomains();

    $services = $sites['services'];
    $english = $languages['en'];

    $deleted = SiteDomain::factory()->create([
        'site_id' => $services->getKey(),
        'language_id' => $english->getKey(),
        'scheme' => 'http',
        'domain' => 'localhost',
        'port' => 8000,
        'path' => '/shared',
    ]);
    $deleted->delete();

    // Another site has since claimed the deleted domain's address.
    SiteDomain::factory()->create([
        'site_id' => $sites['laravel']->getKey(),
        'language_id' => $languages['de']->getKey(),
        'scheme' => 'http',
        'domain' => 'localhost',
        'port' => 8000,
        'path' => '/shared',
        'status' => false,
    ]);

    expect(RepairPageUrlsMissingSiteDomainsAction::run())->toBe(2);

    $deleted->refresh();

    expect($deleted->trashed())->toBeFalse()
        ->and($deleted->status)->toBeTrue()
        ->and($deleted->path)->toBe('/en')
        ->and(FindPageUrlsMissingSiteDomainsAction::run())->toBeEmpty()
        ->and(array_unique(activeRoutingIdentities()))->toHaveCount(count(activeRoutingIdentities()));
});

it('leaves a language unrepaired instead of failing when every address it could take is routed elsewhere', function (): void {
    ['languages' => $languages, 'sites' => $sites] = seedDemoStateWithMissingSiteDomains();

    foreach (['/en', '/fr'] as $path) {
        SiteDomain::factory()->create([
            'site_id' => $sites['laravel']->getKey(),
            'language_id' => $languages['de']->getKey(),
            'scheme' => 'http',
            'domain' => 'localhost',
            'port' => 8000,
            'path' => $path,
            'status' => false,
        ]);
    }

    expect(RepairPageUrlsMissingSiteDomainsAction::run())->toBe(0)
        ->and(FindPageUrlsMissingSiteDomainsAction::run())->toHaveCount(6);
});
