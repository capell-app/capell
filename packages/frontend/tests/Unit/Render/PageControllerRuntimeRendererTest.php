<?php

declare(strict_types=1);

use Capell\Core\Contracts\Pageable;
use Capell\Core\Enums\FrontendRuntime;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Core\ThemeStudio\Data\ThemeDefinitionData;
use Capell\Core\ThemeStudio\Theme\ThemeRegistry;
use Capell\Frontend\Contracts\FrontendContextReader;
use Capell\Frontend\Contracts\FrontendResponseRenderer;
use Capell\Frontend\Contracts\SystemPageResolver;
use Capell\Frontend\Data\FrontendRenderContextData;
use Capell\Frontend\Enums\RenderingStrategyEnum;
use Capell\Frontend\Http\Controllers\PageController;
use Capell\Frontend\Support\Render\FrontendResponseRendererRegistry;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

it('renders public pages through the active theme runtime renderer', function (): void {
    $page = Page::factory()->make(['id' => 123]);
    $site = Site::factory()->make(['id' => 456]);
    $language = Language::factory()->make(['id' => 789]);
    $theme = new Theme;
    $theme->key = 'nexus';

    resolve(ThemeRegistry::class)->register(
        new ThemeDefinitionData(
            key: 'nexus',
            name: 'Nexus',
            description: 'Dark SaaS editorial Inertia theme.',
            package: 'capell-app/theme-inertia-nexus',
            previewImage: '/preview.jpg',
            tags: [],
            bestFit: [],
            presets: [],
            runtime: FrontendRuntime::Inertia,
        ),
    );

    app()->instance(FrontendContextReader::class, new readonly class($page, $site, $language, $theme) implements FrontendContextReader
    {
        public function __construct(
            private Pageable $page,
            private Site $site,
            private Language $language,
            private Theme $theme,
        ) {}

        public function site(): Site
        {
            return $this->site;
        }

        public function language(): Language
        {
            return $this->language;
        }

        public function page(): Pageable
        {
            return $this->page;
        }

        public function layout(): ?Layout
        {
            return null;
        }

        public function theme(): Theme
        {
            return $this->theme;
        }

        public function params(): array
        {
            return [];
        }

        public function slug(): ?string
        {
            return null;
        }

        public function isError(): bool
        {
            return false;
        }

        public function setFrontendData(string $key, mixed $value): self
        {
            return $this;
        }

        public function getFrontendData(?string $key = null): mixed
        {
            return null;
        }
    });

    $renderer = new class implements FrontendResponseRenderer
    {
        public ?FrontendRenderContextData $context = null;

        public function runtime(): FrontendRuntime
        {
            return FrontendRuntime::Inertia;
        }

        public function render(FrontendRenderContextData $context): SymfonyResponse
        {
            $this->context = $context;

            return response('inertia-rendered', $context->status ?? 200);
        }
    };

    resolve(FrontendResponseRendererRegistry::class)->register($renderer);

    $response = resolve(PageController::class)();
    assert($response instanceof SymfonyResponse);

    expect($response->getContent())->toBe('inertia-rendered')
        ->and($renderer->context)->toBeInstanceOf(FrontendRenderContextData::class)
        ->and($renderer->context?->page)->toBe($page)
        ->and($renderer->context?->theme)->toBe($theme);
});

it('preserves resolved frontend error context when the page is already set', function (): void {
    $page = Page::factory()->make([
        'id' => 123,
        'meta' => ['rendering_strategy' => RenderingStrategyEnum::FullLivewire->value],
    ]);
    $site = Site::factory()->make(['id' => 456]);
    $language = Language::factory()->make(['id' => 789]);

    app()->instance(FrontendContextReader::class, new readonly class($page, $site, $language) implements FrontendContextReader
    {
        public function __construct(
            private Pageable $page,
            private Site $site,
            private Language $language,
        ) {}

        public function site(): Site
        {
            return $this->site;
        }

        public function language(): Language
        {
            return $this->language;
        }

        public function page(): Pageable
        {
            return $this->page;
        }

        public function layout(): ?Layout
        {
            return null;
        }

        public function theme(): ?Theme
        {
            return null;
        }

        public function params(): array
        {
            return [];
        }

        public function slug(): ?string
        {
            return null;
        }

        public function isError(): bool
        {
            return true;
        }

        public function setFrontendData(string $key, mixed $value): self
        {
            return $this;
        }

        public function getFrontendData(?string $key = null): mixed
        {
            return null;
        }
    });

    $renderer = new class implements FrontendResponseRenderer
    {
        public ?FrontendRenderContextData $context = null;

        public function runtime(): FrontendRuntime
        {
            return FrontendRuntime::Livewire;
        }

        public function render(FrontendRenderContextData $context): SymfonyResponse
        {
            $this->context = $context;

            return response('error-rendered', $context->status ?? 200);
        }
    };

    resolve(FrontendResponseRendererRegistry::class)->register($renderer);

    $response = resolve(PageController::class)();
    assert($response instanceof SymfonyResponse);

    expect($response->getStatusCode())->toBe(SymfonyResponse::HTTP_NOT_FOUND)
        ->and($renderer->context?->isError)->toBeTrue()
        ->and($renderer->context?->status)->toBe(SymfonyResponse::HTTP_NOT_FOUND)
        ->and($renderer->context?->runtimeManifest?->usesLivewire)->toBeTrue();
});

it('returns a non-cacheable service unavailable response when no renderer is registered for the runtime', function (): void {
    $page = Page::factory()->make(['id' => 123]);
    $site = Site::factory()->make(['id' => 456]);
    $language = Language::factory()->make(['id' => 789]);
    $theme = new Theme;
    $theme->key = 'nexus';

    resolve(ThemeRegistry::class)->register(
        new ThemeDefinitionData(
            key: 'nexus',
            name: 'Nexus',
            description: 'Dark SaaS editorial Inertia theme.',
            package: 'capell-app/theme-inertia-nexus',
            previewImage: '/preview.jpg',
            tags: [],
            bestFit: [],
            presets: [],
            runtime: FrontendRuntime::Inertia,
        ),
    );

    app()->instance(FrontendContextReader::class, new readonly class($page, $site, $language, $theme) implements FrontendContextReader
    {
        public function __construct(
            private Pageable $page,
            private Site $site,
            private Language $language,
            private Theme $theme,
        ) {}

        public function site(): Site
        {
            return $this->site;
        }

        public function language(): Language
        {
            return $this->language;
        }

        public function page(): Pageable
        {
            return $this->page;
        }

        public function layout(): ?Layout
        {
            return null;
        }

        public function theme(): Theme
        {
            return $this->theme;
        }

        public function params(): array
        {
            return [];
        }

        public function slug(): ?string
        {
            return null;
        }

        public function isError(): bool
        {
            return false;
        }

        public function setFrontendData(string $key, mixed $value): self
        {
            return $this;
        }

        public function getFrontendData(?string $key = null): mixed
        {
            return null;
        }
    });

    $response = resolve(PageController::class)();
    assert($response instanceof SymfonyResponse);

    expect($response->getStatusCode())->toBe(SymfonyResponse::HTTP_SERVICE_UNAVAILABLE)
        ->and($response->getContent())->toContain('Frontend unavailable')
        ->and($response->getContent())->toContain('Inertia frontend renderer')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('renders the configured not found system page when no frontend page resolved', function (): void {
    config()->set('capell-frontend.system_pages.auto_create_missing', false);

    $language = Language::factory()->create(['id' => 789]);
    $site = Site::factory()->recycle($language)->withTranslations($language)->create(['id' => 456]);
    $errorPage = Page::factory()->make(['id' => 321]);

    $frontendContext = new class($site, $language) implements FrontendContextReader
    {
        /** @var array<string, mixed> */
        public array $data = [];

        public function __construct(
            private readonly Site $site,
            private readonly Language $language,
        ) {}

        public function site(): Site
        {
            return $this->site;
        }

        public function language(): Language
        {
            return $this->language;
        }

        public function page(): ?Pageable
        {
            return null;
        }

        public function layout(): ?Layout
        {
            return null;
        }

        public function theme(): ?Theme
        {
            return null;
        }

        public function params(): array
        {
            return [];
        }

        public function slug(): ?string
        {
            return null;
        }

        public function isError(): bool
        {
            return false;
        }

        public function setFrontendData(string $key, mixed $value): self
        {
            $this->data[$key] = $value;

            return $this;
        }

        public function getFrontendData(?string $key = null): mixed
        {
            return $key === null ? $this->data : ($this->data[$key] ?? null);
        }
    };

    app()->instance(FrontendContextReader::class, $frontendContext);

    $systemPageResolver = new readonly class($errorPage) implements SystemPageResolver
    {
        public function __construct(private Pageable $page) {}

        public function resolve(string $type, Site $site, Language $language): Pageable
        {
            return $this->page;
        }
    };

    app()->instance('tests.frontend.system-page-resolver', $systemPageResolver);
    app()->tag(['tests.frontend.system-page-resolver'], SystemPageResolver::TAG);

    $renderer = new class implements FrontendResponseRenderer
    {
        public ?FrontendRenderContextData $context = null;

        public function runtime(): FrontendRuntime
        {
            return FrontendRuntime::Blade;
        }

        public function render(FrontendRenderContextData $context): SymfonyResponse
        {
            $this->context = $context;

            return response('custom-404', $context->status ?? 200);
        }
    };

    resolve(FrontendResponseRendererRegistry::class)->register($renderer);

    $response = resolve(PageController::class)();
    assert($response instanceof SymfonyResponse);

    expect($response->getStatusCode())->toBe(SymfonyResponse::HTTP_NOT_FOUND)
        ->and($response->getContent())->toBe('custom-404')
        ->and($renderer->context?->page)->toBe($errorPage)
        ->and($renderer->context?->site)->toBe($site)
        ->and($renderer->context?->language)->toBe($language)
        ->and($renderer->context?->isError)->toBeTrue()
        ->and($frontendContext->getFrontendData('publicPageRenderData'))->not->toBeNull()
        ->and($frontendContext->getFrontendData('performanceReport'))->not->toBeNull();
});

it('returns safe path fallback blade views as public html', function (): void {
    $viewDirectory = resource_path('views');
    $viewPath = $viewDirectory . '/safe-fallback.blade.php';

    File::ensureDirectoryExists($viewDirectory);
    File::put($viewPath, '<main>Safe fallback content</main>');

    app()->instance('request', Request::create('/safe-fallback'));
    app()->instance(FrontendContextReader::class, new class implements FrontendContextReader
    {
        public function site(): ?Site
        {
            return null;
        }

        public function language(): ?Language
        {
            return null;
        }

        public function page(): ?Pageable
        {
            return null;
        }

        public function layout(): ?Layout
        {
            return null;
        }

        public function theme(): ?Theme
        {
            return null;
        }

        public function params(): array
        {
            return [];
        }

        public function slug(): ?string
        {
            return null;
        }

        public function isError(): bool
        {
            return false;
        }

        public function setFrontendData(string $key, mixed $value): self
        {
            return $this;
        }

        public function getFrontendData(?string $key = null): mixed
        {
            return null;
        }
    });

    try {
        $response = resolve(PageController::class)();
        assert($response instanceof SymfonyResponse);
    } finally {
        File::delete($viewPath);
    }

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getContent())->toContain('Safe fallback content');
});

it('guards fallback blade views before returning public html', function (): void {
    $viewDirectory = resource_path('views');
    $viewPath = $viewDirectory . '/fallback-guard.blade.php';

    File::ensureDirectoryExists($viewDirectory);
    File::put($viewPath, '<div data-model-id="42">Unsafe fallback</div>');

    app()->instance('request', Request::create('/fallback-guard'));
    app()->instance(FrontendContextReader::class, new class implements FrontendContextReader
    {
        public function site(): ?Site
        {
            return null;
        }

        public function language(): ?Language
        {
            return null;
        }

        public function page(): ?Pageable
        {
            return null;
        }

        public function layout(): ?Layout
        {
            return null;
        }

        public function theme(): ?Theme
        {
            return null;
        }

        public function params(): array
        {
            return [];
        }

        public function slug(): ?string
        {
            return null;
        }

        public function isError(): bool
        {
            return false;
        }

        public function setFrontendData(string $key, mixed $value): self
        {
            return $this;
        }

        public function getFrontendData(?string $key = null): mixed
        {
            return null;
        }
    });

    $thrown = null;

    try {
        resolve(PageController::class)();
    } catch (RuntimeException $runtimeException) {
        $thrown = $runtimeException;
    } finally {
        File::delete($viewPath);
    }

    expect($thrown)->toBeInstanceOf(RuntimeException::class)
        ->and($thrown?->getMessage())->toContain('Public HTML contains an authoring marker.');
});

it('guards named route fallback blade views before returning public html', function (): void {
    $viewDirectory = resource_path('views/named/fallback');
    $viewPath = $viewDirectory . '/guard.blade.php';
    $request = Request::create('/route-fallback-guard');
    $request->setRouteResolver(fn (): Route => new Route('GET', '/route-fallback-guard', [])->name('named.fallback.guard'));

    File::ensureDirectoryExists($viewDirectory);
    File::put($viewPath, '<a href="/admin/pages/1/edit?signature=abc">Edit</a>');

    app()->instance('request', $request);
    app()->instance(FrontendContextReader::class, new class implements FrontendContextReader
    {
        public function site(): ?Site
        {
            return null;
        }

        public function language(): ?Language
        {
            return null;
        }

        public function page(): ?Pageable
        {
            return null;
        }

        public function layout(): ?Layout
        {
            return null;
        }

        public function theme(): ?Theme
        {
            return null;
        }

        public function params(): array
        {
            return [];
        }

        public function slug(): ?string
        {
            return null;
        }

        public function isError(): bool
        {
            return false;
        }

        public function setFrontendData(string $key, mixed $value): self
        {
            return $this;
        }

        public function getFrontendData(?string $key = null): mixed
        {
            return null;
        }
    });

    $thrown = null;

    try {
        resolve(PageController::class)();
    } catch (RuntimeException $runtimeException) {
        $thrown = $runtimeException;
    } finally {
        File::delete($viewPath);
    }

    expect($thrown)->toBeInstanceOf(RuntimeException::class)
        ->and($thrown?->getMessage())->toContain('Public HTML contains a signed admin URL.');
});
