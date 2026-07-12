<?php

declare(strict_types=1);

use Capell\Core\Data\Interactions\InteractionTargetData;
use Capell\Core\Data\Interactions\InteractionTriggerData;
use Capell\Core\Enums\InteractionBehavior;
use Capell\Core\Enums\InteractionTargetType;
use Capell\Core\Enums\InteractionTriggerEvent;
use Capell\Frontend\Actions\BuildInteractionRenderDataAction;
use Capell\Frontend\Contracts\WidgetInteractionLocatorResolver;

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
