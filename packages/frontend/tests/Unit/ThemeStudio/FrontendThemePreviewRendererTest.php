<?php

declare(strict_types=1);

use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
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
