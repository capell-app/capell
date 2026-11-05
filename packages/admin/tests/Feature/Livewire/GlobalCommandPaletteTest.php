<?php

declare(strict_types=1);

use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Livewire\GlobalCommandPalette;
use Livewire\Livewire;

it('renders a labelled modal and a labelled search field', function (): void {
    CapellAdmin::shouldReceive('getPaletteCommands')->andReturn([]);

    Livewire::test(GlobalCommandPalette::class)
        ->assertSeeHtml('role="dialog"')
        ->assertSeeHtml('aria-modal="true"')
        ->assertSeeHtml('aria-label="Command palette"')
        ->assertSeeHtml('for="command-palette-search"')
        ->assertSeeHtml('id="command-palette-search"')
        ->assertSee('Search commands')
        ->assertSee('Keyboard shortcuts')
        ->assertSee('Go to Pages')
        ->assertSee('⌘/Ctrl + S');
});
