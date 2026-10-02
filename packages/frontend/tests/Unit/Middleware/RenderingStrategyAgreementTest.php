<?php

declare(strict_types=1);

use Capell\Core\Enums\FrontendRuntime;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Page;
use Capell\Frontend\Actions\ResolveFrontendRuntimeAction;
use Capell\Frontend\Contracts\FrontendContextReader;
use Capell\Frontend\Enums\RenderingStrategyEnum;
use Capell\Frontend\Http\Middleware\RenderingStrategyMiddleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

it('keeps the response header and runtime strategy in agreement without repeat blueprint queries', function (
    ?RenderingStrategyEnum $pageStrategy,
    ?RenderingStrategyEnum $blueprintStrategy,
    bool $livewireBlueprint,
    bool $loaded,
    RenderingStrategyEnum $expectedStrategy,
): void {
    $blueprintAttributes = [
        'is_livewire' => $livewireBlueprint,
        'meta' => $blueprintStrategy instanceof RenderingStrategyEnum ? ['rendering_strategy' => $blueprintStrategy->value] : [],
    ];
    $blueprint = $loaded
        ? new Blueprint($blueprintAttributes)
        : Blueprint::factory()->page()->create($blueprintAttributes);

    $page = new Page([
        'blueprint_id' => $loaded ? null : $blueprint->id,
        'meta' => $pageStrategy instanceof RenderingStrategyEnum ? ['rendering_strategy' => $pageStrategy->value] : [],
    ]);

    if ($loaded) {
        $page->setRelation('blueprint', $blueprint);
    }

    $context = Mockery::mock(FrontendContextReader::class);
    $context->shouldReceive('page')->andReturn($page);
    $context->shouldReceive('theme')->andReturnNull();
    $context->shouldReceive('layout')->andReturnNull();

    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        $resolution = ResolveFrontendRuntimeAction::run($context);
        $response = new RenderingStrategyMiddleware($context)->handle(
            Request::create('/strategy-agreement'),
            fn (): Response => new Response('<html><body>Public page</body></html>', Response::HTTP_OK),
        );

        $repeatedResolution = ResolveFrontendRuntimeAction::run($context);
        $blueprintQueries = collect(DB::getQueryLog())
            ->filter(fn (array $query): bool => str_contains($query['query'], '"blueprints"'));
    } finally {
        DB::disableQueryLog();
    }

    expect($resolution)->not->toBeNull()
        ->and($response->headers->get('X-Rendering-Strategy'))->toBe($resolution->runtimeManifest->renderingStrategy->value)
        ->and($resolution->runtimeManifest->renderingStrategy)->toBe($expectedStrategy)
        ->and($resolution->runtime)->toBe($expectedStrategy === RenderingStrategyEnum::FullLivewire ? FrontendRuntime::Livewire : FrontendRuntime::Blade)
        ->and($repeatedResolution->runtimeManifest->renderingStrategy)->toBe($expectedStrategy)
        ->and($response->getContent())->toBe('<html><body>Public page</body></html>')
        ->and($response->getStatusCode())->toBe(Response::HTTP_OK)
        ->and($blueprintQueries)->toHaveCount($loaded ? 0 : 1);
})->with([
    'loaded blueprint strategy' => [null, RenderingStrategyEnum::FullLivewire, false, true, RenderingStrategyEnum::FullLivewire],
    'unloaded blueprint strategy' => [null, RenderingStrategyEnum::FullLivewire, false, false, RenderingStrategyEnum::FullLivewire],
    'loaded Livewire blueprint' => [null, RenderingStrategyEnum::BladeOnly, true, true, RenderingStrategyEnum::FullLivewire],
    'unloaded Livewire blueprint' => [null, RenderingStrategyEnum::BladeOnly, true, false, RenderingStrategyEnum::FullLivewire],
    'page Livewire strategy' => [RenderingStrategyEnum::FullLivewire, RenderingStrategyEnum::BladeOnly, false, true, RenderingStrategyEnum::FullLivewire],
    'page islands strategy' => [RenderingStrategyEnum::BladeWithIslands, RenderingStrategyEnum::FullLivewire, false, true, RenderingStrategyEnum::BladeWithIslands],
    'page Blade strategy overrides blueprint metadata' => [RenderingStrategyEnum::BladeOnly, RenderingStrategyEnum::FullLivewire, false, true, RenderingStrategyEnum::BladeOnly],
    'Livewire blueprint overrides page metadata' => [RenderingStrategyEnum::BladeOnly, RenderingStrategyEnum::BladeOnly, true, true, RenderingStrategyEnum::FullLivewire],
    'blueprint islands strategy' => [null, RenderingStrategyEnum::BladeWithIslands, false, true, RenderingStrategyEnum::BladeWithIslands],
    'default strategy' => [null, null, false, true, RenderingStrategyEnum::BladeOnly],
]);
