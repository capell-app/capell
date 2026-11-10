<?php

declare(strict_types=1);

use Capell\Core\Models\Page;
use Capell\Frontend\Data\FrontendRenderContextData;
use Capell\Frontend\Support\Render\PublicRenderDataContributorRegistry;
use Illuminate\Contracts\View\Factory;
use Workbench\App\Support\CaseStudyRenderDataContributor;
use Workbench\App\Support\CustomPageFieldsFixture;

beforeEach(function (): void {
    resolve(Factory::class)->addNamespace('custom-page-fields', dirname(__DIR__, 5) . '/workbench/resources/views/custom-page-fields');
    resolve(PublicRenderDataContributorRegistry::class)->register(new CaseStudyRenderDataContributor);
    Page::factory()->withTranslations()->createOne();
    CustomPageFieldsFixture::initialize('http://127.0.0.1:8197');
});

it('serves escaped client data through the anonymous standard public route', function (): void {
    $page = CustomPageFieldsFixture::page('public');
    $page->update(['meta' => ['case_study' => ['client_name' => '<script>alert(1)</script>'], 'editorial' => ['reference' => 'PRIVATE-REVIEW']]]);

    $this->get('http://127.0.0.1:8197/custom-page-fields-public')
        ->assertOk()
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertDontSee('PRIVATE-REVIEW', false)
        ->assertDontSee('meta.case_study', false)
        ->assertDontSee('data-capell-authoring', false)
        ->assertDontSee(base_path(), false);
});

it('omits an empty client and declares the page dependency in hydrated public data', function (): void {
    $page = CustomPageFieldsFixture::page('public');
    $page->update(['meta' => ['case_study' => ['client_name' => '']]]);

    $context = new FrontendRenderContextData($page, $page->site, $page->site->language, $page->layout, $page->site->theme);
    $contributor = new CaseStudyRenderDataContributor;

    expect($contributor->contribute($context)->value->clientName)->toBeNull()
        ->and($contributor->metadata($context)->cacheDependencies[0]->modelId)->toBe($page->getKey())
        ->and($contributor->cacheDependencyModelTypes())->toBe([Page::class]);

    $this->get('http://127.0.0.1:8197/custom-page-fields-public')->assertOk()->assertDontSee('<p class="client">', false);
});
