<?php

declare(strict_types=1);

use Capell\Core\Data\Interactions\InteractionTargetData;
use Capell\Core\Data\Interactions\InteractionTriggerData;
use Capell\Core\Enums\InteractionBehavior;
use Capell\Core\Enums\InteractionTargetType;
use Capell\Core\Enums\InteractionTriggerEvent;
use Capell\Frontend\Actions\BuildInteractionRenderDataAction;
use Capell\Frontend\Contracts\Fragments\PublicFragmentReferenceCodec;
use Capell\Frontend\Contracts\Fragments\PublicFragmentUrlResolver;
use Capell\Frontend\Contracts\WidgetInteractionLocatorResolver;
use Capell\Frontend\Data\Fragments\PublicFragmentReferenceData;
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

function fragmentInteractionTrigger(string $fragmentReference = 'not-an-encrypted-reference'): InteractionTriggerData
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
            fragmentReference: $fragmentReference,
        ),
    );
}

function publicFragmentInteractionToken(string $owner = 'layout-builder'): string
{
    return resolve(PublicFragmentReferenceCodec::class)->encode(new PublicFragmentReferenceData(
        owner: $owner,
        formatVersion: 1,
        pageableType: 'page',
        pageableId: 41,
        siteId: 7,
        languageId: 3,
        contentVersion: 'version-1',
        ownerContext: ['widgetKey' => 'hero'],
    ));
}

function bindPublicFragmentInteractionResolver(string $owner, string $url): void
{
    app()->bind('test.public-fragment-url-resolver', fn (): PublicFragmentUrlResolver => new readonly class($owner, $url) implements PublicFragmentUrlResolver
    {
        public function __construct(private string $registeredOwner, private string $resolvedUrl) {}

        public function owner(): string
        {
            return $this->registeredOwner;
        }

        public function url(PublicFragmentReferenceData $reference): string
        {
            return $this->resolvedUrl . '/' . $reference->contentVersion;
        }
    });

    app()->tag('test.public-fragment-url-resolver', PublicFragmentUrlResolver::TAG);
}

it('delegates encrypted fragment interaction URLs to the registered owner', function (): void {
    bindPublicFragmentInteractionResolver('layout-builder', 'https://example.test/_fragments/layout');
    $token = publicFragmentInteractionToken();

    $rendered = BuildInteractionRenderDataAction::run([fragmentInteractionTrigger($token)]);

    expect($rendered)->toHaveCount(1)
        ->and($rendered[0]['target_url'])->toBe('https://example.test/_fragments/layout/version-1')
        ->and(json_encode($rendered[0], JSON_THROW_ON_ERROR))
        ->not->toContain($token)
        ->not->toContain('Capell\\');
});

it('drops fragment interactions when no resolver owns the reference', function (): void {
    bindPublicFragmentInteractionResolver('marketing', 'https://example.test/_capell/fragments/marketing');

    expect(BuildInteractionRenderDataAction::run([
        fragmentInteractionTrigger(publicFragmentInteractionToken('layout-builder')),
    ]))->toBe([]);
});

it('drops malformed fragment interaction references', function (): void {
    bindPublicFragmentInteractionResolver('layout-builder', 'https://example.test/_fragments/layout');

    expect(BuildInteractionRenderDataAction::run([fragmentInteractionTrigger()]))->toBe([]);
});

it('filters fragment interactions without warning-level noise when no owner is installed', function (): void {
    Log::shouldReceive('debug')->once();
    Log::shouldReceive('warning')->never();

    expect(BuildInteractionRenderDataAction::run([
        fragmentInteractionTrigger(publicFragmentInteractionToken()),
    ]))->toBe([]);
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
