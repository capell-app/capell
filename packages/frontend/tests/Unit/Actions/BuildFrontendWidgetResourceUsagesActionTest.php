<?php

declare(strict_types=1);

use Capell\Core\Data\Presentation\PresentationSettingsData;
use Capell\Core\Enums\PresentationLoadingStrategy;
use Capell\Core\Models\Layout;
use Capell\Frontend\Actions\BuildFrontendWidgetResourceUsagesAction;
use Capell\Frontend\Contracts\FrontendWidgetResourceUsageContributor;
use Capell\Frontend\Data\Assets\FrontendWidgetResourceUsageData;
use Capell\Frontend\Data\FrontendRenderContextData;

function widgetResourceUsage(
    string $widgetKey,
    string $resourceGroup,
    string $publicId,
    ?PresentationLoadingStrategy $loadingStrategy = null,
): FrontendWidgetResourceUsageData {
    return new FrontendWidgetResourceUsageData(
        widgetKey: $widgetKey,
        resourceGroup: $resourceGroup,
        publicId: $publicId,
        presentation: new PresentationSettingsData(
            loadingStrategy: $loadingStrategy ?? PresentationLoadingStrategy::Eager,
        ),
        loadingStrategy: $loadingStrategy,
    );
}

function tagWidgetResourceUsageContributor(string $key, object $contributor): void
{
    app()->instance($key, $contributor);
    app()->tag([$key], FrontendWidgetResourceUsageContributor::TAG);
}

it('returns selected widget usages when no contributors are tagged', function (): void {
    $context = new FrontendRenderContextData(
        page: null,
        site: null,
        language: null,
        layout: new Layout([
            'containers' => [[
                'widget_key' => 'gallery',
                'resources' => ['groups' => ['package.gallery']],
                'public_id' => 'gallery-1',
            ]],
        ]),
        theme: null,
    );

    expect(BuildFrontendWidgetResourceUsagesAction::run($context))->toBe([
        [
            'widgetKey' => 'gallery',
            'resourceGroup' => 'package.gallery',
            'publicId' => 'gallery-1',
            'loadingStrategy' => null,
        ],
    ]);
});

it('keeps contributor order and contributor precedence while deduplicating selected usages', function (): void {
    $first = widgetResourceUsage('gallery', 'package.gallery', 'gallery-1');
    $second = widgetResourceUsage('map', 'package.map', 'map-1', PresentationLoadingStrategy::Idle);

    tagWidgetResourceUsageContributor('test.frontend-widget-usage.first', new class($first, $second) implements FrontendWidgetResourceUsageContributor
    {
        public function __construct(
            private readonly FrontendWidgetResourceUsageData $first,
            private readonly FrontendWidgetResourceUsageData $second,
        ) {}

        public function usages(FrontendRenderContextData $context): array
        {
            return [$this->first, $this->second];
        }
    });

    tagWidgetResourceUsageContributor('test.frontend-widget-usage.second', new class($second) implements FrontendWidgetResourceUsageContributor
    {
        public function __construct(private readonly FrontendWidgetResourceUsageData $usage) {}

        public function usages(FrontendRenderContextData $context): array
        {
            return [$this->usage];
        }
    });

    $context = new FrontendRenderContextData(
        page: null,
        site: null,
        language: null,
        layout: new Layout([
            'containers' => [[
                'widget_key' => 'gallery',
                'resources' => [
                    'groups' => ['package.gallery'],
                    'loading_overrides' => [[
                        'group' => 'package.gallery',
                        'loading_strategy' => PresentationLoadingStrategy::Eager->value,
                    ]],
                ],
                'public_id' => 'gallery-1',
            ]],
        ]),
        theme: null,
    );

    $usages = BuildFrontendWidgetResourceUsagesAction::run($context);

    expect($usages)->toHaveCount(2)
        ->and($usages[0])->toBe($first)
        ->and($usages[1])->toBe($second);
});

it('ignores invalid tagged services and invalid contributor results', function (): void {
    tagWidgetResourceUsageContributor('test.frontend-widget-usage.invalid-service', new stdClass);
    tagWidgetResourceUsageContributor('test.frontend-widget-usage.invalid-result', new class implements FrontendWidgetResourceUsageContributor
    {
        public function usages(FrontendRenderContextData $context): array
        {
            /** @phpstan-ignore return.type */
            return [new stdClass, widgetResourceUsage('gallery', 'package.gallery', 'gallery-1')];
        }
    });

    $usages = BuildFrontendWidgetResourceUsagesAction::run(
        new FrontendRenderContextData(null, null, null, null, null),
    );

    expect($usages)->toHaveCount(1)
        ->and($usages[0])->toBeInstanceOf(FrontendWidgetResourceUsageData::class);
});
