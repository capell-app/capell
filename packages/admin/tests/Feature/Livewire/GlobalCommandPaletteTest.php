<?php

declare(strict_types=1);

use BezhanSalleh\FilamentShield\Support\Utils;
use Capell\Admin\Data\AdminSurfaceContributionData;
use Capell\Admin\Data\PaletteCommandData;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Pages\SettingsPage;
use Capell\Admin\Livewire\GlobalCommandPalette;
use Capell\Admin\Support\PaletteAccess;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\PaletteWorkspaceResource;
use Capell\Admin\Tests\Fixtures\Models\RealSiteScopedAdminResourceUser;
use Capell\Admin\Tests\Support\SiteScopedUserFactory;
use Capell\Core\Models\Site;
use Capell\Core\Support\Permissions\PermissionTeamContext;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

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

it('filters destination shortcuts from the full admin page', function (): void {
    config()->set('capell-admin.shortcuts', [
        ['sequence' => 'g p', 'action' => 'navigate', 'target' => 'pages', 'label' => 'Private pages shortcut'],
        ['sequence' => 'g s', 'action' => 'navigate', 'target' => 'settings', 'label' => 'Private settings shortcut'],
        ['key' => '/', 'action' => 'focus', 'target' => '#search', 'label' => 'Public search shortcut'],
    ]);

    $role = Role::findOrCreate(Utils::getPanelUserRoleName(), 'web');
    $this->actingAs($this->createUserWithRole($role));
    $this->get('/admin')->assertOk()
        ->assertDontSee('Private pages shortcut')->assertDontSee('Private settings shortcut')
        ->assertDontSee('/admin/pages')->assertDontSee('/admin/settings')
        ->assertSee('Public search shortcut');

    $this->actingAsAdmin();
    $this->get('/admin')->assertOk()
        ->assertSee('Private pages shortcut')->assertSee('Private settings shortcut')
        ->assertSee('/admin/pages')->assertSee('/admin/settings')
        ->assertSee('Public search shortcut');
});

it('retains site scoped commands on a Livewire update', function (): void {
    config(['permission.teams' => true, 'auth.providers.users.model' => RealSiteScopedAdminResourceUser::class]);
    $registrar = resolve(PermissionRegistrar::class);
    $registrar->teams = true;
    $registrar->forgetCachedPermissions();

    try {
        $site = Site::factory()->createOne();
        $user = SiteScopedUserFactory::create($site, ['View:SettingsPage']);
        $this->actingAs($user)->withSession(['capell.current_site_id' => $site->getKey()]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $palette = PermissionTeamContext::run($site->getKey(), fn () => Livewire::test(GlobalCommandPalette::class), $user);
        $palette->assertSeeHtml('href="' . SettingsPage::getUrl() . '"')
            ->set('query', 'settings')
            ->assertSeeHtml('href="' . SettingsPage::getUrl() . '"');
        expect($registrar->getPermissionsTeamId())->toBeNull();
    } finally {
        auth()->logout();
        $registrar->setPermissionsTeamId(null);
        $registrar->teams = false;
        $registrar->forgetCachedPermissions();
        config(['permission.teams' => false]);
    }
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

it('includes authorised themes and publishing studio shortcuts in full page HTML', function (): void {
    $resource = PaletteWorkspaceResource::class;
    CapellAdmin::contributeToAdminSurface(AdminSurfaceContributionData::resource($resource, 'Workspace'));
    Filament::getPanel('admin')->resources([$resource]);
    $role = Role::findOrCreate(Utils::getPanelUserRoleName(), 'web');
    $user = $this->createUserWithRole($role);
    $this->actingAs($user);
    $this->get('/admin')->assertOk()->assertDontSee('Go to Themes')->assertDontSee('Go to PublishingStudio')
        ->assertDontSee('/admin/themes')->assertDontSee('/admin/publishing-studio');
    $user->givePermissionTo([
        Permission::findOrCreate('ViewAny:Theme', 'web'),
        Permission::findOrCreate('ViewAny:Workspace', 'web'),
    ]);
    $this->get('/admin')->assertOk()->assertSee('Go to Themes')->assertSee('Go to PublishingStudio')
        ->assertSee('g t')->assertSee('g w')->assertSee('/admin/publishing-studio');
});

it('retains extension destination shortcuts with arbitrary command IDs and resolved URLs', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    config()->set('capell-admin.shortcuts', [
        ['sequence' => 'g x', 'action' => 'navigate', 'target' => 'extension-destination', 'label' => 'Extension path shortcut'],
        ['sequence' => 'g y', 'action' => 'navigate', 'target' => 'extension.report', 'label' => 'Extension ID shortcut'],
        ['sequence' => 'g z', 'action' => 'navigate', 'target' => 'extension.action', 'label' => 'Non-navigation command shortcut'],
    ]);
    CapellAdmin::registerCommand(new PaletteCommandData(id: 'extension.other-id', label: 'Extension destination', url: url('/admin/extension-destination')));
    CapellAdmin::registerCommand(new PaletteCommandData(id: 'extension.report', label: 'Weekly report', url: url('/admin/reports/weekly')));
    CapellAdmin::registerCommand(new PaletteCommandData(id: 'extension.action', label: 'Action command', alpineHandler: 'window.test()'));
    $this->actingAsAdmin();
    $this->get('/admin')->assertOk()->assertSee('Extension path shortcut')->assertSee('Extension ID shortcut')
        ->assertDontSee('Non-navigation command shortcut');
    $shortcuts = resolve(PaletteAccess::class)->forRender()->shortcuts;
    expect(array_column($shortcuts, 'url'))->toBe([url('/admin/extension-destination'), url('/admin/reports/weekly')]);
});
