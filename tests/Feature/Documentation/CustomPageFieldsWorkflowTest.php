<?php

declare(strict_types=1);

use Capell\Admin\Filament\Resources\Pages\Pages\EditPage;
use Capell\Core\Models\Page;
use Capell\Frontend\Support\Render\PublicRenderDataContributorRegistry;
use Illuminate\Contracts\View\Factory;
use Illuminate\Translation\Translator;
use Livewire\Livewire;
use Workbench\App\Support\CaseStudyRenderDataContributor;
use Workbench\App\Support\CustomPageFieldsFixture;

it('saves and reopens a case study before serving its changed client anonymously at the same canonical URL', function (): void {
    CustomPageFieldsFixture::registerSchemaExtenders();
    resolve(Translator::class)->addNamespace('capell-extension-cookbook', dirname(__DIR__, 3) . '/workbench/fixtures/extension-cookbook/lang');
    resolve(Translator::class)->addNamespace('custom-page-fields', dirname(__DIR__, 3) . '/workbench/lang/custom-page-fields');
    resolve(Factory::class)->addNamespace('custom-page-fields', dirname(__DIR__, 3) . '/workbench/resources/views/custom-page-fields');
    resolve(PublicRenderDataContributorRegistry::class)->register(new CaseStudyRenderDataContributor);
    Page::factory()->withTranslations()->createOne();
    CustomPageFieldsFixture::initialize('http://127.0.0.1:8197');
    $page = CustomPageFieldsFixture::page('public');
    $canonical = $page->pageUrls()->whereNull('type')->firstOrFail()->url;
    $client = 'Field Notes & <Studio>';
    test()->actingAsAdmin();

    Livewire::test(EditPage::class, ['record' => $page->getRouteKey()])
        ->fillForm(['meta' => ['case_study' => ['client_name' => $client]]])
        ->call('save')
        ->assertHasNoFormErrors();
    Livewire::test(EditPage::class, ['record' => $page->getRouteKey()])
        ->assertFormSet(['meta.case_study.client_name' => $client]);

    auth()->logout();
    $this->assertGuest();
    $this->get('http://127.0.0.1:8197' . $canonical)
        ->assertOk()
        ->assertSee('Field Notes &amp; &lt;Studio&gt;', false)
        ->assertDontSee('Field Notes & <Studio>', false)
        ->assertDontSee('meta.case_study', false)
        ->assertDontSee('data-capell-authoring', false)
        ->assertDontSee('data-capell-editor', false)
        ->assertDontSee(base_path(), false);
});
