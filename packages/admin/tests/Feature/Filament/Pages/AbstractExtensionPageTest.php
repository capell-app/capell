<?php

declare(strict_types=1);

use Capell\Admin\Facades\CapellAdmin;
use Capell\Core\Facades\CapellCore;
use Capell\Tests\Fixtures\Filament\Pages\ExampleExtensionPage;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;

uses(CreatesAdminUser::class);

/**
 * @return list<Action|ActionGroup>
 */
function exampleExtensionPageHeaderActions(): array
{
    $page = new ExampleExtensionPage;
    $method = new ReflectionMethod($page, 'getHeaderActions');

    /** @var list<Action|ActionGroup> $actions */
    $actions = $method->invoke($page);

    return $actions;
}

it('adds a documentation header action when the package declares a documentation url', function (): void {
    test()->actingAsAdmin();

    CapellCore::registerPackage(
        name: 'vendor/documented-extension',
        path: __DIR__,
        version: '1.0.0',
    );
    CapellCore::getPackage('vendor/documented-extension')->documentationUrl = 'https://docs.capell.app/packages/documented-extension';
    CapellCore::forcePackageInstalled('vendor/documented-extension');

    CapellAdmin::registerExtensionPage('vendor/documented-extension', ExampleExtensionPage::class);

    $documentationAction = collect(exampleExtensionPageHeaderActions())
        ->first(fn (mixed $action): bool => $action instanceof Action && $action->getName() === 'extensionDocumentation');

    expect($documentationAction)->toBeInstanceOf(Action::class);

    if (! $documentationAction instanceof Action) {
        return;
    }

    expect($documentationAction->getUrl())->toBe('https://docs.capell.app/packages/documented-extension')
        ->and($documentationAction->shouldOpenUrlInNewTab())->toBeTrue();
});

it('omits the documentation header action when the package has no documentation url', function (): void {
    test()->actingAsAdmin();

    CapellCore::registerPackage(
        name: 'vendor/undocumented-extension',
        path: __DIR__,
        version: '1.0.0',
    );
    CapellCore::forcePackageInstalled('vendor/undocumented-extension');

    CapellAdmin::registerExtensionPage('vendor/undocumented-extension', ExampleExtensionPage::class);

    $documentationAction = collect(exampleExtensionPageHeaderActions())
        ->first(fn (mixed $action): bool => $action instanceof Action && $action->getName() === 'extensionDocumentation');

    expect($documentationAction)->toBeNull();
});
