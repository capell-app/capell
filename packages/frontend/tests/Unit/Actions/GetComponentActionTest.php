<?php

declare(strict_types=1);

use Capell\Core\Actions\GetComponentClassAction;
use Capell\Frontend\Livewire\Page\Page;
use Capell\Tests\Support\Concerns\TestingFrontend;

uses(TestingFrontend::class);

it('returns component class for livewire component', function (): void {
    $component = 'capell::page.page';

    $componentClass = GetComponentClassAction::run($component, livewire: true);

    expect($componentClass)
        ->toBe(Page::class);
});

it('returns component string for blade component', function (): void {
    $component = 'capell::page.results';

    $componentClass = GetComponentClassAction::run($component);

    expect($componentClass)
        ->toBe('capell::components.page.results');
});
