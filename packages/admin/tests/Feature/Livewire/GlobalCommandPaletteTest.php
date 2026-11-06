<?php

declare(strict_types=1);

use Capell\Admin\Data\PaletteCommandData;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Pages\SettingsPage;
use Capell\Admin\Livewire\GlobalCommandPalette;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(CreatesAdminUser::class);

it('renders a labelled modal and a labelled search field', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAsUser();
    Livewire::test(GlobalCommandPalette::class)
        ->assertSeeHtml('role="dialog"')
        ->assertSeeHtml('aria-modal="true"')
        ->assertSeeHtml('aria-label="Command palette"')
        ->assertSeeHtml('for="command-palette-search"')
        ->assertSeeHtml('id="command-palette-search"')
        ->assertSee('Search commands')
        ->assertSee('Keyboard shortcuts')
        ->assertSee('Focus global search')
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

it('shows destination shortcuts and commands only when the user has access', function (string $target, string $permission): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    config()->set('capell-admin.shortcuts', [[
        'sequence' => 'g x',
        'action' => 'navigate',
        'target' => $target,
        'label' => 'Go to ' . ucfirst($target),
    ]]);

    $this->actingAsUser();
    expect(array_column((new GlobalCommandPalette)->filteredCommands(), 'id'))->not->toContain('capell.' . $target);
    Livewire::test(GlobalCommandPalette::class)
        ->assertDontSee('Go to ' . ucfirst($target));

    $this->actingAs($this->createUserWithPermission(Permission::findOrCreate($permission, 'web')));
    $palette = new GlobalCommandPalette;
    $command = collect($palette->filteredCommands())->firstWhere('id', 'capell.' . $target)
        ?? throw new RuntimeException('Expected an accessible core command.');
    Livewire::test(GlobalCommandPalette::class)
        ->assertSee('Go to ' . ucfirst($target))
        ->assertSeeHtml('href="' . $command->url . '"');
})->with([
    'pages' => ['pages', 'ViewAny:Page'],
    'sites' => ['sites', 'ViewAny:Site'],
    'extensions' => ['extensions', 'View:ExtensionsPage'],
    'settings' => ['settings', 'View:SettingsPage'],
]);

it('sorts extension commands together with core commands', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs($this->createUserWithPermission(Permission::findOrCreate('ViewAny:Page', 'web')));
    CapellAdmin::registerCommand(new PaletteCommandData(id: 'extension.first', label: 'Extension first', sort: 1));

    expect(array_column((new GlobalCommandPalette)->filteredCommands(), 'id'))
        ->toBe(['extension.first', 'capell.pages']);
});

it('lets an extension replace a core command by id', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs($this->createUserWithPermission(Permission::findOrCreate('ViewAny:Page', 'web')));
    CapellAdmin::registerCommand(new PaletteCommandData(id: 'capell.pages', label: 'Replacement pages', url: '/replacement'));

    $commands = (new GlobalCommandPalette)->filteredCommands();

    expect($commands)->toHaveCount(1)
        ->and($commands[0]->label)->toBe('Replacement pages')
        ->and($commands[0]->url)->toBe('/replacement');
});

it('mounts the palette and shortcuts for an authenticated panel user', function (): void {
    $this->actingAsAdmin();
    CapellAdmin::registerCommand(new PaletteCommandData(id: 'extension.visible', label: 'Visible extension command', url: '/visible-extension-command'));
    config()->set('capell-admin.shortcuts', [['key' => 'x', 'action' => 'custom', 'target' => 'test', 'label' => 'Visible shortcut label']]);

    $this->get('/admin')->assertOk()
        ->assertSee('Command palette')
        ->assertSee('capellKeyboardShortcuts')
        ->assertSee('Visible extension command')
        ->assertSee('/visible-extension-command')
        ->assertSee('Visible shortcut label');
});

it('does not expose the palette or shortcuts on the login page', function (): void {
    CapellAdmin::registerCommand(new PaletteCommandData(id: 'extension.secret', label: 'Private extension command', url: '/private-extension-command'));
    config()->set('capell-admin.shortcuts', [['key' => 'x', 'action' => 'custom', 'target' => 'test', 'label' => 'Private shortcut label']]);

    $this->get('/admin/login')->assertOk()
        ->assertDontSee('Command palette')
        ->assertDontSee('capellKeyboardShortcuts')
        ->assertDontSee('Private extension command')
        ->assertDontSee('/private-extension-command')
        ->assertDontSee('Private shortcut label');
});

it('does not expose commands or palette content in a direct guest Livewire request', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    CapellAdmin::registerCommand(new PaletteCommandData(id: 'extension.secret', label: 'Private extension command', url: '/private-extension-command'));
    config()->set('capell-admin.shortcuts', [['key' => 'x', 'action' => 'custom', 'target' => 'test', 'label' => 'Private shortcut label']]);

    expect((new GlobalCommandPalette)->filteredCommands())->toBe([]);
    Livewire::test(GlobalCommandPalette::class)
        ->call('toggle')
        ->assertDontSee('Command palette')
        ->assertDontSee('Private extension command')
        ->assertDontSee('/private-extension-command')
        ->assertDontSee('Private shortcut label');
});
