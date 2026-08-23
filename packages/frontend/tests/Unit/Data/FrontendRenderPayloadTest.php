<?php

declare(strict_types=1);

use Capell\Frontend\Data\FrontendRenderPayload;
use Capell\Frontend\Data\FrontendRuntimeManifestData;
use Capell\Frontend\Enums\FrontendRenderAudience;
use Capell\Frontend\Enums\RenderingStrategyEnum;

it('defaults every field when the bag is empty', function (): void {
    $payload = FrontendRenderPayload::fromBag([]);

    expect($payload->runtimeManifest)->toBeNull()
        ->and($payload->publicPageRenderData)->toBeNull()
        ->and($payload->resourcePlan)->toBeNull()
        ->and($payload->mediaHints)->toBe([])
        ->and($payload->lcpMediaUrl)->toBeNull()
        ->and($payload->performanceReport)->toBeNull()
        ->and($payload->publicHtmlSafetyInspected)->toBeFalse()
        ->and($payload->publicHtmlSafetyInspectedHash)->toBeNull()
        ->and($payload->renderedFrontendResources)->toBeNull()
        ->and($payload->renderAudience)->toBeNull();
});

it('carries correctly typed values through unchanged', function (): void {
    $manifest = new FrontendRuntimeManifestData(
        renderingStrategy: RenderingStrategyEnum::BladeOnly,
        usesLivewire: true,
        usesAlpine: false,
        usesBeacon: false,
        usesWireNavigate: true,
        usesIslands: false,
    );

    $payload = FrontendRenderPayload::fromBag([
        'runtimeManifest' => $manifest,
        'lcpMediaUrl' => 'https://example.test/hero.webp',
        'publicHtmlSafetyInspected' => true,
        'publicHtmlSafetyInspectedHash' => 'abc123',
        'renderAudience' => FrontendRenderAudience::Public,
    ]);

    expect($payload->runtimeManifest)->toBe($manifest)
        ->and($payload->lcpMediaUrl)->toBe('https://example.test/hero.webp')
        ->and($payload->publicHtmlSafetyInspected)->toBeTrue()
        ->and($payload->publicHtmlSafetyInspectedHash)->toBe('abc123')
        ->and($payload->renderAudience)->toBe(FrontendRenderAudience::Public);
});

it('discards a wrong-typed value instead of surfacing it as the declared type', function (): void {
    $payload = FrontendRenderPayload::fromBag([
        'runtimeManifest' => 'not a manifest',
        'lcpMediaUrl' => 123,
        'publicHtmlSafetyInspectedHash' => false,
        'mediaHints' => 'not an array',
    ]);

    expect($payload->runtimeManifest)->toBeNull()
        ->and($payload->lcpMediaUrl)->toBeNull()
        ->and($payload->publicHtmlSafetyInspectedHash)->toBeNull()
        ->and($payload->mediaHints)->toBe([]);
});

it('resolves a raw string renderAudience the same way PublicViewQueryGuard/AssertPublicRenderContractAction did inline before this fix', function (): void {
    expect(FrontendRenderPayload::fromBag(['renderAudience' => 'public'])->renderAudience)
        ->toBe(FrontendRenderAudience::Public)
        ->and(FrontendRenderPayload::fromBag(['renderAudience' => 'preview'])->renderAudience)
        ->toBe(FrontendRenderAudience::Preview)
        ->and(FrontendRenderPayload::fromBag(['renderAudience' => 'not-a-real-audience'])->renderAudience)
        ->toBe(FrontendRenderAudience::Public);
});
