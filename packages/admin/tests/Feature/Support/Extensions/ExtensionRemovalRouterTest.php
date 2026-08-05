<?php

declare(strict_types=1);

use Capell\Admin\Contracts\Extensions\ExtensionRemovalCoordinator;
use Capell\Admin\Data\Extensions\ExtensionRemovalRequestData;
use Capell\Admin\Enums\Extensions\ExtensionRemovalMode;
use Capell\Admin\Support\Extensions\ExtensionRemovalRouter;
use Capell\Admin\Support\Extensions\InRequestExtensionRemovalCoordinator;
use Capell\Admin\Tests\Feature\Support\Extensions\Fixtures\RecordingRemovalCoordinator;

function removalRequest(): ExtensionRemovalRequestData
{
    return new ExtensionRemovalRequestData(
        composerName: 'capell-app/routed-extension',
        packageNames: ['capell-app/routed-extension'],
        deletePackage: true,
        deleteData: false,
        extensionSlug: 'routed-extension',
        extensionName: 'Routed Extension',
    );
}

function bindRemovalCoordinator(ExtensionRemovalMode $mode, bool $accepted = true): RecordingRemovalCoordinator
{
    $coordinator = new RecordingRemovalCoordinator($mode, $accepted);

    app()->instance(ExtensionRemovalCoordinator::class, $coordinator);

    return $coordinator;
}

it('hands the removal to the coordinator and stops when the site queues removals', function (): void {
    $coordinator = bindRemovalCoordinator(ExtensionRemovalMode::Queued);

    $shouldRemoveHere = ExtensionRemovalRouter::shouldRemoveInRequest(removalRequest(), 'Routed Extension');

    expect($shouldRemoveHere)->toBeFalse()
        ->and($coordinator->queuedRequests)->toHaveCount(1)
        ->and($coordinator->queuedRequests[0]->composerName)->toBe('capell-app/routed-extension')
        ->and($coordinator->queuedRequests[0]->deletePackage)->toBeTrue();
});

it('still stops, and says why, when the coordinator refuses to queue', function (): void {
    bindRemovalCoordinator(ExtensionRemovalMode::Queued, accepted: false);

    // A refusal must not fall through to an in-request Composer write: the
    // whole point of routing was to stop doing that.
    expect(ExtensionRemovalRouter::shouldRemoveInRequest(removalRequest(), 'Routed Extension'))->toBeFalse();
});

it('discloses instructions and removes nothing on a manual-only site', function (): void {
    $coordinator = bindRemovalCoordinator(ExtensionRemovalMode::ManualInstructions);

    expect(ExtensionRemovalRouter::shouldRemoveInRequest(removalRequest(), 'Routed Extension'))->toBeFalse()
        ->and($coordinator->queuedRequests)->toBe([]);
});

it('performs the removal in this request when nothing else can, having warned first', function (): void {
    $coordinator = bindRemovalCoordinator(ExtensionRemovalMode::InRequest);

    expect(ExtensionRemovalRouter::shouldRemoveInRequest(removalRequest(), 'Routed Extension'))->toBeTrue()
        ->and($coordinator->queuedRequests)->toBe([]);
});

it('defaults to the in-request path so a site without the marketplace keeps working', function (): void {
    app()->bind(ExtensionRemovalCoordinator::class, InRequestExtensionRemovalCoordinator::class);

    expect(resolve(ExtensionRemovalCoordinator::class)->modeFor('capell-app/routed-extension'))
        ->toBe(ExtensionRemovalMode::InRequest);
});

it('names both commands in the fallback manual instructions', function (): void {
    $instructions = new InRequestExtensionRemovalCoordinator()
        ->manualInstructions('capell-app/routed-extension', 'Routed Extension');

    expect($instructions)
        ->toContain('capell:extension-uninstall')
        ->toContain('composer remove');
});

it('refuses in words rather than by throwing when asked to queue with nothing to queue onto', function (): void {
    $outcome = new InRequestExtensionRemovalCoordinator()->queue(removalRequest());

    expect($outcome->accepted)->toBeFalse()
        ->and($outcome->body)->toContain('capell-app/routed-extension');
});
