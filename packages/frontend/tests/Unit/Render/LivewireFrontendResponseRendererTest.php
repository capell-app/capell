<?php

declare(strict_types=1);

use Capell\Core\Data\RenderableDefinitionData;
use Capell\Core\Enums\FrontendRuntime;
use Capell\Core\Enums\LivewirePageComponentEnum;
use Capell\Core\Enums\RenderableTypeEnum;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Page;
use Capell\Core\Support\Renderables\RenderableRegistry;
use Capell\Frontend\Data\FrontendRenderContextData;
use Capell\Frontend\Support\Render\FrontendResponseRendererRegistry;
use Capell\Frontend\Support\Render\LivewireFrontendResponseRenderer;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

it('declares the livewire runtime', function (): void {
    $renderer = new LivewireFrontendResponseRenderer;

    expect($renderer->runtime())->toBe(FrontendRuntime::Livewire);
});

it('registers the livewire renderer in the frontend renderer registry', function (): void {
    $renderer = resolve(FrontendResponseRendererRegistry::class)
        ->forRuntime(FrontendRuntime::Livewire);

    expect($renderer)->toBeInstanceOf(LivewireFrontendResponseRenderer::class);
});

it('applies the render context status to markdown responses', function (): void {
    app()->bind('capell.frontend.page-markdown-response', fn (): callable => fn (): ResponseFactory|Response => response('markdown'));

    $response = (new LivewireFrontendResponseRenderer)->render(new FrontendRenderContextData(
        page: null,
        site: null,
        language: null,
        layout: null,
        theme: null,
        status: 404,
    ));

    expect($response->getStatusCode())->toBe(404)
        ->and($response->getContent())->toBe('markdown');
});

it('rejects authoring markers returned by the livewire markdown response path', function (): void {
    app()->bind('capell.frontend.page-markdown-response', fn (): callable => fn (): ResponseFactory|Response => response('<div data-field-path="title">Unsafe</div>'));

    (new LivewireFrontendResponseRenderer)->render(new FrontendRenderContextData(
        page: null,
        site: null,
        language: null,
        layout: null,
        theme: null,
    ));
})->throws(RuntimeException::class, 'Public HTML contains an authoring marker.');

it('rejects signed admin urls returned by the livewire markdown response path', function (): void {
    app()->bind('capell.frontend.page-markdown-response', fn (): callable => fn (): ResponseFactory|Response => response('<a href="/admin/pages/1/edit?signature=plain-signature">Edit</a>'));

    (new LivewireFrontendResponseRenderer)->render(new FrontendRenderContextData(
        page: null,
        site: null,
        language: null,
        layout: null,
        theme: null,
    ));
})->throws(RuntimeException::class, 'Public HTML contains a signed admin URL.');

it('rejects authoring markers from the primary livewire component response path', function (): void {
    app()->instance('livewire', new class
    {
        public function new(string $component): object
        {
            return new class
            {
                public function __invoke(): Response
                {
                    return response('<div data-model-id="42">Unsafe</div>');
                }
            };
        }
    });

    $type = Blueprint::factory()->page()->make([
        'meta' => [],
    ]);
    $page = Page::factory()->make();
    $page->setRelation('type', $type);

    (new LivewireFrontendResponseRenderer)->render(new FrontendRenderContextData(
        page: $page,
        site: null,
        language: null,
        layout: null,
        theme: null,
    ));
})->throws(RuntimeException::class, 'Public HTML contains an authoring marker.');

it('selects the page type livewire component when configured', function (): void {
    resolve(RenderableRegistry::class)->register(new RenderableDefinitionData(
        key: 'app.page.show',
        type: RenderableTypeEnum::Page,
        livewire: 'custom::page.show',
    ));

    $requestedComponents = [];
    app()->instance('livewire', new class($requestedComponents)
    {
        /**
         * @param  array<int, string>  $requestedComponents
         */
        public function __construct(
            private array &$requestedComponents,
        ) {}

        public function new(string $component): object
        {
            $this->requestedComponents[] = $component;

            return new class
            {
                public function __invoke(): Response
                {
                    return response('rendered');
                }
            };
        }
    });

    $type = Blueprint::factory()->page()->make([
        'component' => 'app.page.show',
    ]);
    $page = Page::factory()->make();
    $page->setRelation('type', $type);

    (new LivewireFrontendResponseRenderer)->render(new FrontendRenderContextData(
        page: $page,
        site: null,
        language: null,
        layout: null,
        theme: null,
    ));

    expect($requestedComponents)->toBe(['custom::page.show']);
});

it('falls back to the default livewire page component', function (): void {
    $requestedComponents = [];
    app()->instance('livewire', new class($requestedComponents)
    {
        /**
         * @param  array<int, string>  $requestedComponents
         */
        public function __construct(
            private array &$requestedComponents,
        ) {}

        public function new(string $component): object
        {
            $this->requestedComponents[] = $component;

            return new class
            {
                public function __invoke(): Response
                {
                    return response('rendered');
                }
            };
        }
    });

    $type = Blueprint::factory()->page()->make([
        'meta' => [],
    ]);
    $page = Page::factory()->make();
    $page->setRelation('type', $type);

    (new LivewireFrontendResponseRenderer)->render(new FrontendRenderContextData(
        page: $page,
        site: null,
        language: null,
        layout: null,
        theme: null,
    ));

    expect($requestedComponents)->toBe([LivewirePageComponentEnum::Default->value]);
});

it('does not lazy load the page type while resolving the livewire component', function (): void {
    $requestedComponents = [];
    app()->instance('livewire', new class($requestedComponents)
    {
        /**
         * @param  array<int, string>  $requestedComponents
         */
        public function __construct(
            private array &$requestedComponents,
        ) {}

        public function new(string $component): object
        {
            $this->requestedComponents[] = $component;

            return new class
            {
                public function __invoke(): Response
                {
                    return response('rendered');
                }
            };
        }
    });

    $type = Blueprint::factory()->page()->create([
        'component' => 'app.page.show',
    ]);
    $page = Page::factory()
        ->type($type)
        ->create()
        ->unsetRelation('type');

    DB::flushQueryLog();
    DB::enableQueryLog();

    (new LivewireFrontendResponseRenderer)->render(new FrontendRenderContextData(
        page: $page,
        site: null,
        language: null,
        layout: null,
        theme: null,
    ));

    expect($requestedComponents)->toBe([LivewirePageComponentEnum::Default->value])
        ->and(DB::getQueryLog())->toBe([]);
});
