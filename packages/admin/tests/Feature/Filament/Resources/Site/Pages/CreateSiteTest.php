<?php

declare(strict_types=1);

use Capell\Admin\Filament\Resources\Sites\Pages\CreateSite;
use Capell\Admin\Filament\Resources\Sites\SiteResource;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Models\Translation;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Support\Str;
use Livewire\Livewire;

use function Pest\Laravel\assertDatabaseHas;

uses(CreatesAdminUser::class)
    ->group('site');

beforeEach(function (): void {
    test()->actingAsAdmin();
});

test('required fields', function (): void {
    $type = Blueprint::factory()->site()->default()->create();

    $newData = Site::factory()
        ->for($type)
        ->make();

    Livewire::test(CreateSite::class)
        ->assertSuccessful()
        ->assertSee(__('capell-admin::generic.site_languages_description'))
        ->assertSee(__('capell-admin::generic.default_pages_helper'))
        ->assertSee(__('capell-admin::generic.custom_pages_helper'))
        ->fillForm([
            'name' => '',
            'language_id' => '',
            'theme_id' => '',
        ])
        ->goToNextWizardStep()
        ->assertHasFormErrors([
            'name' => 'required',
            'language_id' => 'required',
            'theme_id' => 'required',
        ])
        ->fillForm([
            'name' => $newData->name,
            'language_id' => $newData->language->getKey(),
            'theme_id' => $newData->theme->getKey(),
        ])
        ->goToNextWizardStep()
        ->assertHasNoFormErrors();
});

test('domain is required', function (): void {
    $newData = Site::factory()
        ->for(Blueprint::factory()->site()->default())
        ->make();

    Livewire::test(CreateSite::class)
        ->assertSuccessful()
        ->fillForm([
            'name' => $newData->name,
            'language_id' => $newData->language->getKey(),
        ])
        ->assertSchemaStateSet([
            'name' => $newData->name,
            'language_id' => $newData->language->getKey(),
        ])
        ->goToNextWizardStep()
        ->assertHasAllFormErrors()
        ->set('data.site_domains', [])
        ->call('create')
        ->assertHasFormErrors([
            'site_domains' => 'required',
        ]);
});

test('duplicate domain validation names the site using the domain', function (): void {
    Blueprint::factory()->site()->default()->create();
    $language = Language::factory()->default()->create();
    $existingSite = Site::factory()
        ->for($language)
        ->createOne(['name' => 'Existing Site']);

    SiteDomain::factory()
        ->for($existingSite)
        ->for($language)
        ->createOne([
            'scheme' => 'https',
            'domain' => 'example.com',
            'path' => null,
        ]);

    $newData = Site::factory()
        ->for($language)
        ->make();
    $domainKey = (string) Str::uuid();

    Livewire::test(CreateSite::class)
        ->assertSuccessful()
        ->fillForm([
            'name' => $newData->name,
            'theme_id' => $newData->theme->getKey(),
            'language_id' => $language->getKey(),
        ])
        ->goToNextWizardStep()
        ->fillForm([
            'site_domains' => [
                $domainKey => [
                    'url' => 'https://example.com',
                    'language_id' => $language->getKey(),
                    'default' => true,
                ],
            ],
        ])
        ->call('create')
        ->assertHasFormErrors([
            sprintf('site_domains.%s.url', $domainKey),
        ])
        ->assertSee(__('capell-admin::message.site_domain_taken_by_site', [
            'site' => 'Existing Site',
        ]));
});

test('create site', function (): void {
    Blueprint::factory()->site()->default()->create();
    $language = Language::factory()->default()->create();

    $newData = Site::factory()
        ->for($language)
        ->for(Blueprint::factory()->site())
        ->make();

    $livewire = Livewire::test(CreateSite::class)
        ->assertSuccessful()
        ->fillForm([
            'blueprint_id' => $newData->blueprint->getKey(),
            'name' => $newData->name,
            'theme_id' => $newData->theme->getKey(),
            'language_id' => $newData->language->getKey(),
        ])
        ->callMountedAction()
        ->assertSchemaStateSet([
            'name' => $newData->name,
            'blueprint_id' => $newData->blueprint->getKey(),
            'theme_id' => $newData->theme->getKey(),
            'language_id' => $newData->language->getKey(),
        ])
        ->goToNextWizardStep()
        ->fillForm([
            'site_domains' => [
                (string) Str::uuid() => [
                    'url' => 'http://localhost',
                    'language_id' => $newData->language->getKey(),
                ],
            ],
        ])
        ->assertHasNoFormErrors()
        ->call('create')
        ->assertHasNoFormErrors();

    $site = Site::query()
        ->where('name', $newData->name)
        ->where('blueprint_id', $newData->blueprint->getKey())
        ->where('theme_id', $newData->theme->getKey())
        ->where('language_id', $newData->language->getKey())
        ->firstOrFail();

    $livewire->assertRedirect(SiteResource::getUrl('edit', ['record' => $site->getKey()]));

    expect(data_get($site->meta, 'mail.use_site_logo'))->toBeTrue();

    expect($site)
        ->toBeInstanceOf(Site::class)
        ->name->toBe($newData->name)
        ->blueprint_id->toBe($newData->blueprint->getKey())
        ->theme_id->toBe($newData->theme->getKey())
        ->language_id->toBe($newData->language->getKey())
        ->siteDomains->toHaveCount(1)
        ->translations->toHaveCount(1)
        ->and($site->siteDomains->first())
        ->toBeInstanceOf(SiteDomain::class)
        ->domain->toBe('localhost')
        ->path->toBeNull()
        ->scheme->toBe('http')
        ->language_id->toBe($newData->language->getKey())
        ->and($site->translations->first())
        ->toBeInstanceOf(Translation::class)
        ->title->toBe($newData->name)
        ->language_id->toBe($newData->language->getKey());
});

test('create site with variations', function (string $operation): void {
    $siteType = Blueprint::factory()->site()->default()->create();

    [$language, $additionalLanguage] = Language::factory(2)->create();

    switch ($operation) {
        case 'second site':
            Site::factory()->createOne();

            $newData = Site::factory()
                ->state([
                    'language_id' => $language->getKey(),
                    'blueprint_id' => $siteType->id,
                ])
                ->make();
            break;
        case 'with deleted site':
            Site::factory()->createOne()->delete();

            $newData = Site::factory()
                ->state([
                    'language_id' => $language->getKey(),
                    'blueprint_id' => $siteType->id,
                ])
                ->make();
            break;
        case 'with deleted site domain':
            SiteDomain::factory()->createOne()->delete();

            $newData = Site::factory()
                ->state([
                    'language_id' => $language->getKey(),
                    'blueprint_id' => $siteType->id,
                ])
                ->make();
            break;
        default:
            $newData = Site::factory()
                ->state([
                    'language_id' => $language->getKey(),
                    'blueprint_id' => $siteType->id,
                ])
                ->make();
    }

    Livewire::test(CreateSite::class)
        ->assertSuccessful()
        ->fillForm([
            'name' => $newData->name,
            'blueprint_id' => $siteType->id,
            'theme_id' => $newData->theme->getKey(),
            'languages' => [$additionalLanguage->getKey()],
            'language_id' => $language->getKey(),
            'default' => $newData->default,
            'status' => (int) $newData->status,
        ])
        ->assertSchemaStateSet([
            'name' => $newData->name,
            'blueprint_id' => $siteType->id,
            'theme_id' => $newData->theme->getKey(),
            'languages' => [$additionalLanguage->getKey()],
            'language_id' => $language->getKey(),
            'default' => $newData->default,
            'status' => (int) $newData->status,
        ])
        ->goToNextWizardStep()
        ->assertHasNoFormErrors()
        ->fillForm([
            'site_domains' => [
                (string) Str::uuid() => [
                    'url' => 'https://example.com/' . $language->code,
                    'language_id' => $additionalLanguage->getKey(),
                    'default' => false,
                    'use_host_domain' => false,
                ],
                (string) Str::uuid() => [
                    'url' => 'https://example.com/',
                    'language_id' => $language->getKey(),
                    'default' => true,
                    'use_host_domain' => false,
                ],
            ],
        ])
        ->call('create');

    assertDatabaseHas(Site::class, [
        'name' => $newData->name,
        'blueprint_id' => $siteType->id,
        'theme_id' => $newData->theme->getKey(),
        'language_id' => $language->getKey(),
    ]);

    if ($operation === 'second site') {
        expect(Site::query()->count())->toBe(2);

        return;
    }

    assertDatabaseHas(SiteDomain::class, [
        'domain' => 'example.com',
        'path' => null,
        'scheme' => 'https',
        'language_id' => $language->getKey(),
    ]);

    assertDatabaseHas(SiteDomain::class, [
        'domain' => 'example.com',
        'path' => '/' . $language->code,
        'scheme' => 'https',
        'language_id' => $additionalLanguage->getKey(),
    ]);
})
    ->with([
        'default',
        'second site',
        'with delete site',
        'with delete site domain',
    ]);

test('auto creates pages', function (): void {
    $languages = Language::factory()
        ->count(3)
        ->sequence(['default' => true])
        ->create();

    $language = Language::factory()->createOne();

    $newData = Site::factory()
        ->recycle($language)
        ->for(Blueprint::factory()->site()->default())
        ->make();

    $livewire = Livewire::test(CreateSite::class)
        ->assertSuccessful()
        ->fillForm([
            'blueprint_id' => $newData->blueprint->getKey(),
            'name' => $newData->name,
            'theme_id' => $newData->theme->getKey(),
            'language_id' => $newData->language->getKey(),
            'languages' => $languages->pluck('id')->all(),
        ])
        ->assertSchemaStateSet([
            'name' => $newData->name,
            'blueprint_id' => $newData->blueprint->getKey(),
            'theme_id' => $newData->theme->getKey(),
            'language_id' => $newData->language->getKey(),
            'languages' => $languages->pluck('id')->all(),
        ])
        ->goToNextWizardStep()
        ->set(
            'data.site_domains',
            [
                (string) Str::uuid() => [
                    'url' => 'https://example.com',
                    'language_id' => $newData->language->getKey(),
                    'default' => true,
                ],
            ],
        )
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRecordCreated();

    $record = Site::query()
        ->where('name', $newData->name)
        ->where('blueprint_id', $newData->blueprint->getKey())
        ->where('theme_id', $newData->theme->getKey())
        ->where('language_id', $newData->language->getKey())
        ->firstOrFail();

    $record->refresh();

    expect($record)
        ->toBeInstanceOf(Site::class)
        ->name->toBe($newData->name)
        ->blueprint_id->toBe($newData->blueprint->getKey())
        ->theme_id->toBe($newData->theme->getKey())
        ->language_id->toBe($newData->language->getKey())
        ->siteDomains->toHaveCount(1)
        ->translations->toHaveCount(4)
        ->and($record->siteDomains->first())
        ->toBeInstanceOf(SiteDomain::class)
        ->domain->toBe('example.com')
        ->path->toBeNull()
        ->scheme->toBe('https')
        ->language_id->toBe($newData->language->getKey())
        ->and($record->translations->first())
        ->toBeInstanceOf(Translation::class)
        ->title->toBe($newData->name)
        ->language_id->toBe($newData->language->getKey());
});
