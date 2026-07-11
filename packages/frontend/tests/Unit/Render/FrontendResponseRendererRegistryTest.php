<?php

declare(strict_types=1);

use Capell\Core\Enums\FrontendRuntime;
use Capell\Frontend\Contracts\FrontendResponseRenderer;
use Capell\Frontend\Data\FrontendRenderContextData;
use Capell\Frontend\Support\Render\FrontendResponseRendererRegistry;
use Capell\Frontend\Tests\Fixtures\Autoload\RegistryTestRenderer;
use Symfony\Component\HttpFoundation\Response;

it('registers and resolves response renderers by runtime', function (): void {
    $renderer = new class implements FrontendResponseRenderer
    {
        public function runtime(): FrontendRuntime
        {
            return FrontendRuntime::Livewire;
        }

        public function render(FrontendRenderContextData $context): Response
        {
            return response('livewire', $context->status ?? 200);
        }
    };

    $registry = new FrontendResponseRendererRegistry;
    $registry->register($renderer);

    expect($registry->forRuntime(FrontendRuntime::Livewire))->toBe($renderer)
        ->and($registry->has(FrontendRuntime::Livewire))->toBeTrue()
        ->and($registry->has(FrontendRuntime::Inertia))->toBeFalse();
});

it('resolves renderer class registrations through the container for each lookup', function (): void {
    app()->bind(RegistryTestRenderer::class);

    $registry = new FrontendResponseRendererRegistry;
    $registry->registerClass(FrontendRuntime::Inertia, RegistryTestRenderer::class);

    $firstRenderer = $registry->forRuntime(FrontendRuntime::Inertia);
    $secondRenderer = $registry->forRuntime(FrontendRuntime::Inertia);

    expect($firstRenderer)->toBeInstanceOf(RegistryTestRenderer::class)
        ->and($secondRenderer)->toBeInstanceOf(RegistryTestRenderer::class)
        ->and($firstRenderer)->not->toBe($secondRenderer);
});
