<?php

declare(strict_types=1);

use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Pages\SettingsPage;
use Capell\Admin\Livewire\GlobalCommandPalette;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(CreatesAdminUser::class);

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

it('shows a searchable settings command only to users who can access settings', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $permission = Permission::findOrCreate('View:SettingsPage', 'web');
    $this->actingAs($this->createUserWithPermission($permission));

    Livewire::test(GlobalCommandPalette::class)
        ->call('toggle')
        ->set('query', 'settings')
        ->assertSeeHtml('href="' . SettingsPage::getUrl() . '"');

    $this->actingAsUser();
    Livewire::test(GlobalCommandPalette::class)
        ->call('toggle')
        ->set('query', 'settings')
        ->assertDontSeeHtml('href="' . SettingsPage::getUrl() . '"');
});
