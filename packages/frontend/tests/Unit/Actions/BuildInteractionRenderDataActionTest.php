<?php

declare(strict_types=1);

use Capell\Core\Data\Interactions\InteractionTargetData;
use Capell\Core\Data\Interactions\InteractionTriggerData;
use Capell\Core\Enums\InteractionBehavior;
use Capell\Core\Enums\InteractionTargetType;
use Capell\Core\Enums\InteractionTriggerEvent;
use Capell\Frontend\Actions\BuildInteractionRenderDataAction;
use Capell\Frontend\Contracts\DeferredFragmentReferenceBuilder;
use Capell\Frontend\Contracts\WidgetInteractionLocatorResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

function interactionTrigger(): InteractionTriggerData
{
    return new InteractionTriggerData(
        key: 'open-widget',
        label: 'Open',
        icon: null,
        style: 'default',
        event: InteractionTriggerEvent::Click,
        behavior: InteractionBehavior::Modal,
        target: new InteractionTargetData(
            type: InteractionTargetType::Widget,
            widgetType: 'capell-app.slideshow',
            widgetData: ['__capell' => ['instance_id' => 'target-1'], 'secret' => 'never-in-url'],
        ),
    );
}

function fragmentInteractionTrigger(): InteractionTriggerData
{
    return new InteractionTriggerData(
        key: 'open-fragment',
        label: 'Load section',
        icon: null,
        style: 'default',
        event: InteractionTriggerEvent::Click,
        behavior: InteractionBehavior::Modal,
        target: new InteractionTargetData(
            type: InteractionTargetType::Fragment,
            fragmentReference: 'raw-editor-reference-never-public',
        ),
    );
}

it('delegates fragment interaction URLs to the optional deferred fragment reference builder', function (): void {
    app()->bind(DeferredFragmentReferenceBuilder::class, fn (): DeferredFragmentReferenceBuilder => new class implements DeferredFragmentReferenceBuilder
    {
        public function reference(Model $asset, array $meta): string
        {
            return 'unused-in-this-test';
        }

        public function url(string $fragmentReference): ?string
        {
            return $fragmentReference === 'raw-editor-reference-never-public'
                ? 'https://example.test/_capell/fragments/opaque-locator'
                : null;
        }
    });

    $rendered = BuildInteractionRenderDataAction::run([fragmentInteractionTrigger()]);

    expect($rendered)->toHaveCount(1)
        ->and($rendered[0]['target_url'])->toBe('https://example.test/_capell/fragments/opaque-locator')
        ->and(json_encode($rendered[0], JSON_THROW_ON_ERROR))
        ->not->toContain('raw-editor-reference-never-public')
        ->not->toContain('Capell\\');
});

it('drops fragment interactions when the builder rejects the reference', function (): void {
    app()->bind(DeferredFragmentReferenceBuilder::class, fn (): DeferredFragmentReferenceBuilder => new class implements DeferredFragmentReferenceBuilder
    {
        public function reference(Model $asset, array $meta): string
        {
            return 'unused-in-this-test';
        }

        public function url(string $fragmentReference): ?string
        {
            return null;
        }
    });

    expect(BuildInteractionRenderDataAction::run([fragmentInteractionTrigger()]))->toBe([]);
});

it('filters fragment interactions without warning-level noise when no builder is installed', function (): void {
    Log::shouldReceive('debug')->once();
    Log::shouldReceive('warning')->never();

    expect(BuildInteractionRenderDataAction::run([fragmentInteractionTrigger()]))->toBe([]);
});

it('delegates widget interaction URLs to the optional host-neutral locator resolver', function (): void {
    app()->bind(WidgetInteractionLocatorResolver::class, fn (): WidgetInteractionLocatorResolver => new class implements WidgetInteractionLocatorResolver
    {
        public function resolve(InteractionTargetData $target): ?string
        {
            return $target->widgetData['__capell']['instance_id'] === 'target-1'
                ? 'https://example.test/_capell/layout-widgets/short-locator'
                : null;
        }
    });

    $rendered = BuildInteractionRenderDataAction::run([interactionTrigger()]);

    expect($rendered)->toHaveCount(1)
        ->and($rendered[0]['target_url'])->toEndWith('/short-locator')
        ->not->toContain('never-in-url');
});

it('filters widget interactions when no secure locator resolver is installed', function (): void {
    app()->offsetUnset(WidgetInteractionLocatorResolver::class);

    expect(BuildInteractionRenderDataAction::run([interactionTrigger()]))->toBe([]);
});

it('renders lazy widget interactions as ordinary fallback links with accessible runtime labels', function (): void {
    app()->bind(WidgetInteractionLocatorResolver::class, fn (): WidgetInteractionLocatorResolver => new class implements WidgetInteractionLocatorResolver
    {
        public function resolve(InteractionTargetData $target): string
        {
            return '/_capell/layout-widgets/short-locator';
        }
    });

    $html = view('capell::components.interactions.index', [
        'triggers' => [interactionTrigger()],
    ])->render();

    expect($html)->toContain(
        '<a',
        'href="/_capell/layout-widgets/short-locator"',
        'data-capell-interaction',
        'data-capell-interaction-loading-label="Loading content"',
        'data-capell-interaction-status',
    )->not->toContain('<button');
});
