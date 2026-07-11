<?php

declare(strict_types=1);

use Capell\Admin\Filament\Components\Forms\ComponentSelect;
use Capell\Admin\Tests\Fixtures\Livewire;
use Capell\Core\Facades\CapellCore;
use Filament\Schemas\Schema;

function mountedComponentSelect(ComponentSelect $component): ComponentSelect
{
    $mountedComponent = Schema::make(Livewire::make()->data([]))
        ->statePath('data')
        ->components([$component])
        ->getComponents()[0];

    assert($mountedComponent instanceof ComponentSelect);

    return $mountedComponent;
}

it('adds an explicit create component suffix action', function (): void {
    $component = mountedComponentSelect(
        ComponentSelect::make('component')
            ->setupType('Page')
            ->withCreateComponentAction(),
    );

    $action = $component->getSuffixActions()['createComponent'];

    expect($action->getName())->toBe('createComponent')
        ->and($action->isDisabled())->toBeFalse();
});

it('shows source flow hint for selected components', function (): void {
    CapellCore::shouldReceive('getComponents')
        ->withNoArgs()
        ->andReturn([
            'page' => ['Hero' => 'capell-page.hero'],
        ]);
    CapellCore::shouldReceive('getComponentCachePath')
        ->andReturn(base_path('bootstrap/cache/capell-components.php'));

    $component = mountedComponentSelect(
        ComponentSelect::make('component')
            ->withSourceFlow(),
    );

    expect($component->hasHintIcon())->toBeTrue();
});
