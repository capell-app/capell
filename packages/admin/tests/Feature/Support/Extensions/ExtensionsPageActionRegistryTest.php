<?php

declare(strict_types=1);

use Capell\Admin\Filament\Pages\ExtensionsPage;
use Capell\Admin\Support\Extensions\ExtensionsPageActionRegistry;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;

it('deduplicates keyed extension page header actions', function (): void {
    $registry = new ExtensionsPageActionRegistry;
    $page = Mockery::mock(ExtensionsPage::class);

    $registry->registerHeaderAction(Action::make('browseMarketplace'), 'capell-marketplace.browse');
    $registry->registerHeaderAction(Action::make('browseMarketplace'), 'capell-marketplace.browse');

    expect($registry->headerActions($page))->toHaveCount(1);
});

it('resolves registered action objects as fresh instances for each page', function (): void {
    $registry = new ExtensionsPageActionRegistry;
    $pageA = Mockery::mock(ExtensionsPage::class);
    $pageB = Mockery::mock(ExtensionsPage::class);

    $registry->registerHeaderAction(Action::make('browseMarketplace'));

    /** @var Action $actionA */
    $actionA = $registry->headerActions($pageA)[0];
    $actionA->livewire($pageA)->label('Changed for page A');

    /** @var Action $actionB */
    $actionB = $registry->headerActions($pageB)[0];

    expect($actionA)
        ->not->toBe($actionB)
        ->and($actionA->getLivewire())->toBe($pageA)
        ->and($actionB->getLivewire())->toBeNull()
        ->and($actionB->getLabel())->not->toBe('Changed for page A');
});

it('deep clones registered action groups for each page', function (): void {
    $registry = new ExtensionsPageActionRegistry;
    $pageA = Mockery::mock(ExtensionsPage::class);
    $pageB = Mockery::mock(ExtensionsPage::class);

    $registry->registerHeaderActionGroupAction(ActionGroup::make([
        Action::make('install'),
    ]));

    /** @var ActionGroup $groupA */
    $groupA = $registry->headerActionGroupActions($pageA)[0];
    $actionA = $groupA->getActions()[0];
    $actionA->livewire($pageA)->hidden();

    /** @var ActionGroup $groupB */
    $groupB = $registry->headerActionGroupActions($pageB)[0];
    $actionB = $groupB->getActions()[0];

    expect($groupA)
        ->not->toBe($groupB)
        ->and($actionA)->not->toBe($actionB)
        ->and($actionA->getGroup())->toBe($groupA)
        ->and($actionB->getGroup())->toBe($groupB)
        ->and($actionB->getLivewire())->toBeNull()
        ->and($actionB->isHidden())->toBeFalse();
});

it('invokes action factories for every resolution', function (): void {
    $registry = new ExtensionsPageActionRegistry;
    $pageA = Mockery::mock(ExtensionsPage::class);
    $pageB = Mockery::mock(ExtensionsPage::class);
    $invocations = 0;

    $registry->registerTableAction(function (ExtensionsPage $page) use (&$invocations): Action {
        $invocations++;

        return Action::make('factoryAction')->livewire($page);
    });

    $actionA = $registry->tableActions($pageA)[0];
    $actionB = $registry->tableActions($pageB)[0];

    expect($invocations)->toBe(2)
        ->and($actionA)->not->toBe($actionB)
        ->and($actionA->getLivewire())->toBe($pageA)
        ->and($actionB->getLivewire())->toBe($pageB);
});

it('keeps boot registrations across scoped instance flushes', function (): void {
    $registry = resolve(ExtensionsPageActionRegistry::class);
    $page = Mockery::mock(ExtensionsPage::class);

    $registry->registerHeaderAction(Action::make('persistent'), 'persistent');

    app()->forgetScopedInstances();

    $resolvedRegistry = resolve(ExtensionsPageActionRegistry::class);
    $actionNames = array_map(
        fn (Action|ActionGroup $action): string => $action instanceof Action ? ($action->getName() ?? '') : 'group',
        $resolvedRegistry->headerActions($page),
    );

    expect($resolvedRegistry)->toBe($registry)
        ->and($actionNames)->toContain('persistent');
});
