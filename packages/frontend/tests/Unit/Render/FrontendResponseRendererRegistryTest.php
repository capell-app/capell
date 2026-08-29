<?php

declare(strict_types=1);

use Capell\Core\Enums\FrontendRuntime;
use Capell\Core\Octane\Resettable;
use Capell\Core\Support\Extensions\ExtensionContributionReceiptContext;
use Capell\Core\Support\Extensions\ExtensionContributionReceiptRegistry;
use Capell\Frontend\Contracts\FrontendResponseRenderer;
use Capell\Frontend\Data\FrontendRenderContextData;
use Capell\Frontend\Support\Render\BladeFrontendResponseRenderer;
use Capell\Frontend\Support\Render\FrontendResponseRendererRegistry;
use Capell\Frontend\Support\Render\LivewireFrontendResponseRenderer;
use Capell\Frontend\Tests\Fixtures\Autoload\RegistryTestRenderer;
use Symfony\Component\HttpFoundation\Response;

it('emits one receipt for a direct response renderer registration', function (): void {
    $receipts = new ExtensionContributionReceiptRegistry;
    app()->instance(ExtensionContributionReceiptRegistry::class, $receipts);
    $renderer = new class implements FrontendResponseRenderer
    {
        public function runtime(): FrontendRuntime
        {
            return FrontendRuntime::Blade;
        }

        public function render(FrontendRenderContextData $context): Response
        {
            return response('blade', $context->status ?? 200);
        }
    };

    $receipts->withContext(
        ExtensionContributionReceiptContext::forPackage('vendor/renderers', 'frontend', 'Vendor\\Frontend\\Provider'),
        fn (): FrontendResponseRendererRegistry => tap(new FrontendResponseRendererRegistry)->register($renderer),
    );

    expect($receipts->forPackage('vendor/renderers'))->toHaveCount(1)
        ->and($receipts->forPackage('vendor/renderers')[0]->key)->toBe('response-renderer:blade');
});

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

it('restores default renderers without leaking instance registrations between scopes', function (): void {
    $sentinel = new class implements FrontendResponseRenderer
    {
        public function runtime(): FrontendRuntime
        {
            return FrontendRuntime::Inertia;
        }

        public function render(FrontendRenderContextData $context): Response
        {
            return response('inertia', $context->status ?? 200);
        }
    };

    $firstRegistry = resolve(FrontendResponseRendererRegistry::class);
    $firstRegistry->register($sentinel);

    expect($firstRegistry->forRuntime(FrontendRuntime::Inertia))->toBe($sentinel)
        ->and($firstRegistry->forRuntime(FrontendRuntime::Blade))->toBeInstanceOf(BladeFrontendResponseRenderer::class)
        ->and($firstRegistry->forRuntime(FrontendRuntime::Livewire))->toBeInstanceOf(LivewireFrontendResponseRenderer::class)
        ->and(collect(app()->tagged(Resettable::TAG))->contains(
            fn (mixed $service): bool => $service === $firstRegistry,
        ))->toBeFalse();

    app()->forgetScopedInstances();

    $secondRegistry = resolve(FrontendResponseRendererRegistry::class);

    expect($secondRegistry)->not->toBe($firstRegistry)
        ->and($secondRegistry->forRuntime(FrontendRuntime::Inertia))->toBeNull()
        ->and($secondRegistry->forRuntime(FrontendRuntime::Blade))->toBeInstanceOf(BladeFrontendResponseRenderer::class)
        ->and($secondRegistry->forRuntime(FrontendRuntime::Livewire))->toBeInstanceOf(LivewireFrontendResponseRenderer::class)
        ->and(collect(app()->tagged(Resettable::TAG))->contains(
            fn (mixed $service): bool => $service === $secondRegistry,
        ))->toBeFalse();
});
