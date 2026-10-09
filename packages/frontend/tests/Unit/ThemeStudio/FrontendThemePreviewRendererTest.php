<?php

declare(strict_types=1);

use Capell\Core\Enums\FrontendRuntime;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Frontend\Contracts\FrontendResponseRenderer;
use Capell\Frontend\Data\FrontendRenderContextData;
use Capell\Frontend\Enums\FrontendRenderAudience;
use Capell\Frontend\Enums\RenderingStrategyEnum;
use Capell\Frontend\Support\Render\FrontendResponseRendererRegistry;
use Capell\Frontend\Support\State\FrontendState;
use Capell\Frontend\Support\Themes\FrontendThemePreviewRenderer;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

it('renders a theme preview through the page livewire component and seeds public frontend context', function (): void {
    $language = Language::factory()->english()->create();
    $theme = Theme::factory()->create(['key' => 'preview-theme']);
    $site = Site::factory()
        ->recycle($language)
        ->theme($theme)
        ->withTranslations($language, siteDomainData: [
            'domain' => 'preview.test',
            'scheme' => 'https',
            'path' => null,
            'default' => true,
        ])
        ->create();
    $siteDomain = expectPresent($site->siteDomains->first());
    $layout = Layout::factory()->site($site)->create();
    $page = Page::factory()
        ->site($site)
        ->layout($layout)
        ->withTranslations($language, ['title' => 'Previewed Page'], '/previewed-page')
        ->state(['meta' => ['rendering_strategy' => RenderingStrategyEnum::FullLivewire->value]])
        ->create();

    $livewireFactory = new class
    {
        public ?string $component = null;

        public function new(string $component): object
        {
            $this->component = $component;

            return new class
            {
                public function __invoke(): string
                {
                    return 'preview-html';
                }
            };
        }
    };

    app()->instance('livewire', $livewireFactory);

    $response = resolve(FrontendThemePreviewRenderer::class)->render(
        theme: $theme,
        site: $site,
        page: $page,
        language: $language,
        siteDomain: $siteDomain,
    );

    $state = resolve(FrontendState::class);

    expect($response)->toBeInstanceOf(SymfonyResponse::class)
        ->and($response->getContent())->toBe('preview-html')
        ->and($livewireFactory->component)->toBe('capell.page.default')
        ->and($state->site())->toBe($site)
        ->and($state->language())->toBe($language)
        ->and($state->page())->toBe($page)
        ->and($state->layout()?->is($layout))->toBeTrue()
        ->and($state->theme())->toBe($theme)
        ->and($state->domain())->toBe($siteDomain)
        ->and($state->getFrontendData('renderAudience'))->toBe(FrontendRenderAudience::Preview)
        ->and($state->getFrontendData('publicPageRenderData'))->not->toBeNull()
        ->and($site->theme)->toBe($theme)
        ->and($state->layout()?->theme)->toBe($theme);
});

it('preserves response objects returned by preview page components', function (): void {
    $language = Language::factory()->english()->create();
    $theme = Theme::factory()->create(['key' => 'response-preview-theme']);
    $site = Site::factory()
        ->recycle($language)
        ->theme($theme)
        ->withTranslations($language)
        ->create();
    $layout = Layout::factory()->site($site)->create();
    $page = Page::factory()
        ->site($site)
        ->layout($layout)
        ->withTranslations($language, ['title' => 'Response Preview'], '/response-preview')
        ->state(['meta' => ['rendering_strategy' => RenderingStrategyEnum::FullLivewire->value]])
        ->create();

    app()->instance('livewire', new class
    {
        public function new(string $component): object
        {
            return new class
            {
                public function __invoke(): SymfonyResponse
                {
                    return response('already-a-response', 202);
                }
            };
        }
    });

    $response = resolve(FrontendThemePreviewRenderer::class)->render($theme, $site, $page, $language);

    expect($response->getStatusCode())->toBe(202)
        ->and($response->getContent())->toBe('already-a-response');
});

it('prepares a draft blade preview using the supplied theme domain and hydrated language context', function (): void {
    $language = Language::factory()->english()->create();
    $originalTheme = Theme::factory()->create(['key' => 'original-theme']);
    $theme = Theme::factory()->create(['key' => 'selected-theme']);
    $site = Site::factory()->recycle($language)->theme($originalTheme)->withTranslations($language)->create();
    $domain = expectPresent($site->siteDomains->first());
    $layout = Layout::factory()->site($site)->create();
    $page = Page::factory()->site($site)->layout($layout)->withTranslations($language, ['title' => 'Draft Preview'], '/draft-preview')->create();
    $page->setAttribute('visible_from', now()->addYear());

    $renderer = new class implements FrontendResponseRenderer
    {
        public ?FrontendRenderContextData $context = null;

        #[Override]
        public function runtime(): FrontendRuntime
        {
            return FrontendRuntime::Blade;
        }

        #[Override]
        public function render(FrontendRenderContextData $context): SymfonyResponse
        {
            $this->context = $context;

            return response('<main>Draft preview</main>');
        }
    };
    resolve(FrontendResponseRendererRegistry::class)->register($renderer);
    resolve(FrontendState::class)->setFrontendData('stale', 'foreign');

    $response = resolve(FrontendThemePreviewRenderer::class)->render($theme, $site, $page, $language, $domain);

    expect($response->getContent())->toBe('<main>Draft preview</main>')
        ->and($renderer->context?->theme)->toBe($theme)
        ->and($renderer->context?->publicRenderData)->not->toBeNull()
        ->and($page->relationLoaded('translation'))->toBeTrue()
        ->and($page->translation?->language_id)->toBe($language->id)
        ->and($page->relationLoaded('pageUrl'))->toBeTrue()
        ->and(resolve(FrontendState::class)->domain())->toBe($domain)
        ->and(resolve(FrontendState::class)->renderPayload()->renderAudience)->toBe(FrontendRenderAudience::Preview)
        ->and(resolve(FrontendState::class)->getFrontendData('stale'))->toBeNull()
        ->and($response->getContent())->not->toContain('data-capell-edit', 'signed-editor', 'authoring');
});
