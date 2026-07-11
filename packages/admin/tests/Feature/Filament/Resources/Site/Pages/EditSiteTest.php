<?php

declare(strict_types=1);

use Capell\Admin\Filament\Actions\Page\ReplicateSiteAction;
use Capell\Admin\Filament\Resources\Sites\Pages\EditSite;
use Capell\Admin\Tests\Feature\Filament\Resources\Site\Pages\Fixtures\ApiTranslatorContract;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Models\Theme;
use Capell\Core\Models\Translation;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Filament\Actions\DeleteAction;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertSoftDeleted;

use Tanmuhittin\LaravelGoogleTranslate\Translators\ApiTranslate;

uses(CreatesAdminUser::class)
    ->group('site');

beforeEach(function (): void {
    test()->actingAsAdmin();
});

it('can retrieve data', function (): void {
    $site = Site::factory()->createOne();

    Livewire::test(EditSite::class, [
        'record' => $site->getRouteKey(),
    ])
        ->assertSuccessful()
        ->assertSee(__('capell-admin::generic.site_content_description'))
        ->assertSee(__('capell-admin::generic.related_sites_description'))
        ->assertSee(__('capell-admin::generic.site_contact_description'))
        ->assertSee(__('capell-admin::generic.footer_copy_info'))
        ->assertSee(__('capell-admin::generic.site_brand_description'))
        ->assertSee(__('capell-admin::generic.site_media_description'))
        ->assertSchemaStateSet([
            'name' => $site->name,
            'language_id' => $site->language_id,
        ]);
});

it('can save', function (): void {
    $site = Site::factory()->disabled()->hasSiteDomains()->create();
    $contactPage = Page::factory()->site($site)->create();
    $newData = Site::factory()->make();

    Livewire::test(EditSite::class, [
        'record' => $site->getRouteKey(),
    ])
        ->assertSuccessful()
        ->fillForm([
            'name' => $newData->name,
            'language_id' => $newData->language_id,
            'meta.contact_page_id' => $contactPage->id,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($site->refresh())
        ->name->toBe($newData->name)
        ->language_id->toBe($newData->language_id)
        ->meta->contact_page_id->toBe($contactPage->id);
});

it('can edit database-backed site fields from the admin form', function (): void {
    $language = Language::factory()->english()->create();
    $newLanguage = Language::factory()->german()->create();
    $theme = Theme::factory()->createOne();
    $newTheme = Theme::factory()->createOne();
    $relatedSite = Site::factory()->createOne();

    $site = Site::factory()
        ->language($language)
        ->theme($theme)
        ->withTranslations([$language, $newLanguage])
        ->create([
            'name' => 'Capell Ruby',
            'meta' => [
                'business_name' => 'Capell Ruby Ltd',
                'email' => 'hello@example.com',
                'phone' => '0123456789',
                'footer_content' => 'Footer content here',
                'social_links' => [
                    [
                        'type' => 'facebook',
                        'url' => 'https://facebook.com',
                        'icon' => 'fab-square-facebook',
                    ],
                ],
                'related' => [],
            ],
            'admin' => [
                'require_translations' => [$language->code],
            ],
            'order' => 3,
            'default' => true,
            'status' => true,
        ]);

    Livewire::test(EditSite::class, [
        'record' => $site->getRouteKey(),
    ])
        ->assertSuccessful()
        ->assertSchemaStateSet(function (array $state) use ($language, $theme): array {
            expect($state['name'])->toBe('Capell Ruby')
                ->and((int) $state['theme_id'])->toBe($theme->getKey())
                ->and((int) $state['language_id'])->toBe($language->getKey())
                ->and($state['meta']['business_name'])->toBe('Capell Ruby Ltd')
                ->and($state['meta']['email'])->toBe('hello@example.com')
                ->and($state['meta']['phone'])->toBe('0123456789')
                ->and($state['meta']['footer_content'])->toBe('Footer content here')
                ->and(collect($state['meta']['social_links'])->first()['type'])->toBe('facebook')
                ->and($state['admin']['require_translations'])->toBe([$language->code])
                ->and($state['order'])->toBe(3.0)
                ->and((bool) $state['default'])->toBeTrue()
                ->and((bool) $state['status'])->toBeTrue();

            return [];
        })
        ->fillForm([
            'name' => 'Capell Ruby Edited',
            'theme_id' => $newTheme->getKey(),
            'language_id' => $newLanguage->getKey(),
            'meta' => [
                'business_name' => 'Capell Ruby Studio',
                'email' => 'studio@example.com',
                'phone' => '02000000000',
                'footer_content' => 'Updated footer content.',
                'social_links' => [
                    [
                        'type' => 'instagram',
                        'url' => 'https://instagram.com/capell',
                        'icon' => 'fab-square-instagram',
                        'title' => 'Instagram',
                    ],
                ],
                'related' => [$relatedSite->getKey()],
            ],
            'admin' => [
                'require_translations' => [],
            ],
            'order' => 8,
            'default' => false,
            'status' => '0',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($site->refresh())
        ->name->toBe('Capell Ruby Edited')
        ->theme_id->toBe($newTheme->getKey())
        ->language_id->toBe($newLanguage->getKey())
        ->order->toBe(8)
        ->default->toBeFalse()
        ->status->toBeFalse()
        ->meta->toMatchArray([
            'business_name' => 'Capell Ruby Studio',
            'email' => 'studio@example.com',
            'phone' => '02000000000',
            'footer_content' => 'Updated footer content.',
            'social_links' => [
                [
                    'type' => 'instagram',
                    'url' => 'https://instagram.com/capell',
                    'icon' => 'fab-square-instagram',
                    'title' => 'Instagram',
                ],
            ],
            'related' => [$relatedSite->getKey()],
        ])
        ->admin->toMatchArray([
            'require_translations' => [],
        ]);
});

it('can save with the default language marked as a required translation', function (): void {
    $language = Language::factory()->english()->create();
    $site = Site::factory()
        ->language($language)
        ->withTranslations($language)
        ->create([
            'admin' => [
                'require_translations' => [$language->code],
            ],
        ]);

    Livewire::test(EditSite::class, [
        'record' => $site->getRouteKey(),
    ])
        ->assertSuccessful()
        ->call('save')
        ->assertHasNoFormErrors();

    expect($site->refresh()->admin)
        ->toHaveKey('require_translations', [$language->code]);
});

test('validates edit site', function (): void {
    $site = Site::factory()->createOne();

    Livewire::test(EditSite::class, [
        'record' => $site->getRouteKey(),
    ])
        ->assertSuccessful()
        ->fillForm([
            'name' => '',
            'language_id' => '',
        ])
        ->call('save')
        ->assertHasFormErrors([
            'name' => 'required',
            'language_id' => 'required',
        ]);
});

test('can replicate site', function (): void {
    $site = Site::factory()->withTranslations()->create();

    Livewire::test(EditSite::class, [
        'record' => $site->getRouteKey(),
    ])
        ->assertSuccessful()
        ->mountAction(ReplicateSiteAction::class)
        ->assertSchemaStateSet([
            'name' => $site->name . ' (' . __('capell-admin::generic.copy') . ')',
            'language_id' => $site->language->getKey(),
        ])
        ->goToNextWizardStep()
        ->fillForm([
            'site_domains' => $site->siteDomains->map(
                fn (SiteDomain $domain, int $index): array => [
                    'url' => $domain->scheme . '://' . $domain->domain . $domain->path . '/replicated',
                    'language_id' => $domain->language_id,
                    'default' => $domain->default,
                ],
            )
                ->all(),
        ])
        ->callMountedAction()
        ->assertHasNoFormErrors();

    assertDatabaseHas(
        Site::class,
        [
            'name' => $site->name . ' (' . __('capell-admin::generic.copy') . ')',
        ],
    );
});

it('can delete', function (): void {
    $site = Site::factory()->createOne();

    Livewire::test(EditSite::class, [
        'record' => $site->getRouteKey(),
    ])
        ->assertSuccessful()
        ->callAction(DeleteAction::class)
        ->assertHasNoFormErrors();

    assertSoftDeleted($site, ['id' => $site]);
});

test('delete modal warns about affected pages', function (): void {
    $site = Site::factory()->withTranslations()->create();
    $layout = Layout::factory()->createOne(['site_id' => $site->getKey()]);
    Page::factory()->site($site)->layout($layout)->withTranslations()->create();

    Livewire::test(EditSite::class, [
        'record' => $site->getRouteKey(),
    ])
        ->assertSuccessful()
        ->mountAction(DeleteAction::class)
        ->assertMountedActionModalSee('1 page');
});

test('delete action cascades through site-owned records', function (): void {
    $site = Site::factory()->withTranslations()->create();
    $layout = Layout::factory()->createOne(['site_id' => $site->getKey()]);
    $page = Page::factory()->site($site)->layout($layout)->withTranslations()->create();
    $pageUrl = PageUrl::factory()
        ->site($site)
        ->page($page)
        ->state(['language_id' => $site->language_id])
        ->create();

    Livewire::test(EditSite::class, [
        'record' => $site->getRouteKey(),
    ])
        ->assertSuccessful()
        ->callAction(DeleteAction::class)
        ->assertHasNoFormErrors();

    assertSoftDeleted($site);
    assertSoftDeleted($layout);
    assertSoftDeleted($page);
    assertSoftDeleted($pageUrl);
});

it('can translate translations', function (): void {
    $fakeTranslator = new ApiTranslatorContract;
    app()->bind(ApiTranslate::class, fn (): ApiTranslate => new ApiTranslate($fakeTranslator, 1000, 0));

    $languages = [
        Language::factory()->english()->create(),
        Language::factory()->german()->create(),
    ];
    $site = Site::factory()
        ->has(
            Translation::factory()
                ->forEachSequence(
                    ['language_id' => $languages[0]->getKey(), 'title' => 'Test Title'],
                    ['language_id' => $languages[1]->getKey(), 'title' => ''],
                ),
        )
        ->create();

    Livewire::test(EditSite::class, [
        'record' => $site->getRouteKey(),
    ])
        ->assertSuccessful()
        ->assertSchemaComponentStateSet('translations.record-1.title', 'Test Title')
        ->assertSchemaComponentStateSet('translations.record-2.title', '')
        ->callAction(TestAction::make('translate')->schemaComponent('translations', schema: 'form'))
        ->assertHasNoFormErrors()
        ->assertSchemaComponentStateSet('translations.record-1.title', 'Test Title')
        ->assertSchemaComponentStateSet('translations.record-2.title', 'FAKE_TRANSLATION')
        ->call('save')
        ->assertHasNoFormErrors();

    expect($site->refresh())
        ->translations->get(0)->title->toBe('Test Title')
        ->translations->get(1)->title->toBe('FAKE_TRANSLATION');
});
