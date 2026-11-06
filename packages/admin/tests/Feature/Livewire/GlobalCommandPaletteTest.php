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
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Log;
use Livewire\Exceptions\MethodNotFoundException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Process\Process;

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
    expect(resolve(PaletteAccess::class)->forRender()->commands)->not->toHaveKey('capell.' . $target);
    Livewire::test(GlobalCommandPalette::class)
        ->assertDontSee('Go to ' . ucfirst($target));

    $this->actingAs($this->createUserWithPermission(Permission::findOrCreate($permission, 'web')));
    $command = resolve(PaletteAccess::class)->forRender()->commands['capell.' . $target]
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

    expect(array_column(Livewire::test(GlobalCommandPalette::class)->viewData('commands'), 'id'))
        ->toBe(['extension.first', 'capell.pages']);
});

it('reserves core command ids even when their destination is denied', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs($this->createUserWithPermission(Permission::findOrCreate('ViewAny:Page', 'web')));
    CapellAdmin::registerCommand(new PaletteCommandData(id: 'capell.pages', label: 'Replacement pages', url: '/replacement'));

    $logger = Log::spy();
    $commands = resolve(PaletteAccess::class)->forRender()->commands;
    expect($commands)->toHaveCount(1)
        ->and($commands['capell.pages']->label)->toBe(__('capell-admin::palette.pages'))
        ->and($commands['capell.pages']->url)->not->toBe('/replacement');

    $this->actingAsUser();
    Livewire::test(GlobalCommandPalette::class)->assertDontSee('Replacement pages')->assertDontSee('/replacement');
    $logger->shouldHaveReceived('debug')->with('Ignoring extension palette command with reserved core ID.', ['id' => 'capell.pages']);
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

    expect(resolve(PaletteAccess::class)->forRender()->commands)->toBe([]);
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
    $this->get('/admin')->assertOk()->assertDontSee('Go to Themes')->assertDontSee('Go to Publishing Studio')
        ->assertDontSee('/admin/themes')->assertDontSee('/admin/publishing-studio');
    $user->givePermissionTo([
        Permission::findOrCreate('ViewAny:Theme', 'web'),
        Permission::findOrCreate('ViewAny:Workspace', 'web'),
    ]);
    $this->get('/admin')->assertOk()->assertSee('Go to Themes')->assertSee('Go to Publishing Studio')
        ->assertSee('g t')->assertSee('g w')->assertSee('/admin/publishing-studio');
});

it('retains extension destination shortcuts with arbitrary command IDs and resolved URLs', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    config()->set('capell-admin.shortcuts', [
        ['sequence' => 'g x', 'action' => 'navigate', 'target' => 'extension.other-id', 'label' => 'Extension path shortcut'],
        ['sequence' => 'g y', 'action' => 'navigate', 'target' => 'extension.report', 'label' => 'Extension ID shortcut'],
        ['sequence' => 'g z', 'action' => 'navigate', 'target' => 'extension.action', 'label' => 'Non-navigation command shortcut'],
    ]);
    CapellAdmin::registerCommand(new PaletteCommandData(id: 'extension.other-id', label: 'Extension destination', url: url('/admin/extension-destination'), visible: static fn (Authenticatable $actor): bool => true));
    CapellAdmin::registerCommand(new PaletteCommandData(id: 'extension.report', label: 'Weekly report', url: url('/admin/reports/weekly'), visible: static fn (Authenticatable $actor): bool => true));
    CapellAdmin::registerCommand(new PaletteCommandData(id: 'extension.action', label: 'Action command', alpineHandler: 'window.test()'));
    $this->actingAsAdmin();
    $this->get('/admin')->assertOk()->assertSee('Extension path shortcut')->assertSee('Extension ID shortcut')
        ->assertDontSee('Non-navigation command shortcut');
    $shortcuts = resolve(PaletteAccess::class)->forRender()->shortcuts;
    expect(array_column($shortcuts, 'url'))->toBe([url('/admin/extension-destination'), url('/admin/reports/weekly')]);
});

it('does not restore a denied core shortcut through an extension id or URL', function (string $id, string $url): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAsUser();
    CapellAdmin::registerCommand(new PaletteCommandData(id: $id, label: 'Extension themes', url: $url));
    config()->set('capell-admin.shortcuts', [[
        'sequence' => 'g t', 'action' => 'navigate', 'target' => 'themes', 'label' => 'Private themes shortcut',
    ]]);
    Livewire::test(GlobalCommandPalette::class)->assertDontSee('Private themes shortcut');
    expect(resolve(PaletteAccess::class)->forRender()->shortcuts)->toBe([]);
})->with([
    ['themes', '/extension-themes'],
    ['capell.themes', '/extension-themes'],
    ['extension.themes', '/admin/themes'],
]);

it('evaluates boot registered extension visibility for each actor and its shortcut', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $allowed = $this->createUser();
    $denied = $this->createUser();
    $allowedId = $allowed->getAuthIdentifier();
    CapellAdmin::registerCommand(new PaletteCommandData(
        id: 'extension.private',
        label: 'Private report',
        url: '/private-report',
        visible: static fn (Authenticatable $actor): bool => $actor->getAuthIdentifier() === $allowedId,
    ));
    config()->set('capell-admin.shortcuts', [[
        'sequence' => 'g r', 'action' => 'navigate', 'target' => 'extension.private', 'label' => 'Private report shortcut',
    ]]);
    foreach ([$denied, $allowed, $denied] as $actor) {
        $this->actingAs($actor);
        $palette = Livewire::test(GlobalCommandPalette::class);
        if ($actor === $allowed) {
            $palette->assertSee('Private report')->assertSee('Private report shortcut')->assertSee('/private-report');
        } else {
            $palette->assertDontSee('Private report')->assertDontSee('Private report shortcut')->assertDontSee('/private-report');
        }
    }
});

it('requires explicit visibility and an exact extension ID for extension shortcuts', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAsUser();
    CapellAdmin::registerCommand(new PaletteCommandData(id: 'extension.default', label: 'Default extension', url: '/admin/extension-default'));
    CapellAdmin::registerCommand(new PaletteCommandData(
        id: 'extension.explicit',
        label: 'Explicit extension',
        url: '/admin/extension-explicit',
        visible: static fn (Authenticatable $actor): bool => true,
    ));
    config()->set('capell-admin.shortcuts', [
        ['key' => 'd', 'action' => 'navigate', 'target' => 'extension.default', 'label' => 'Implicit shortcut'],
        ['key' => 'e', 'action' => 'navigate', 'target' => 'extension-explicit', 'label' => 'URL path shortcut'],
    ]);
    Livewire::test(GlobalCommandPalette::class)->assertSee('Default extension')->assertSee('Explicit extension')
        ->assertDontSee('Implicit shortcut')->assertDontSee('URL path shortcut');
});

it('does not expose filteredCommands as a Livewire action', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAsUser();
    expect(fn () => Livewire::test(GlobalCommandPalette::class)->call('filteredCommands', ['bad' => [], 'worse' => []]))
        ->toThrow(MethodNotFoundException::class);
});

it('filters the rendered list locally without a Livewire request and resets search on close', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAsUser();
    CapellAdmin::registerCommand(new PaletteCommandData(id: 'create', label: 'Create page', description: 'Add content'));
    CapellAdmin::registerCommand(new PaletteCommandData(id: 'media', label: 'Media library', description: 'Find images'));
    $html = Livewire::test(GlobalCommandPalette::class)->html();
    throw_if($html === '', RuntimeException::class, 'Expected rendered palette HTML.');

    $dom = new DOMDocument;
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);
    $input = ($xpath->query('//*[@id="command-palette-search"]') ?: throw new RuntimeException('Invalid input selector.'))->item(0);
    expect($input)->toBeInstanceOf(DOMElement::class);
    assert($input instanceof DOMElement);
    expect($input->getAttribute('x-model.debounce.250ms'))->toBe('query');
    foreach ($input->attributes as $attribute) {
        expect($attribute->name)->not->toStartWith('wire:model');
    }

    $root = ($xpath->query('//*[@x-data]') ?: throw new RuntimeException('Invalid palette selector.'))->item(0);
    assert($root instanceof DOMElement);
    $process = new Process(['node', '-e', <<<'JS'
        const assert = require('node:assert/strict');
        const window = { Livewire: { find: () => ({ entangle: () => false }) } };
        const state = new Function('window', 'return (' + process.argv[1] + ')')(window);
        let reset;
        state.$watch = (key, callback) => { assert.equal(key, 'open'); reset = callback; };
        state.init();
        for (const [query, label, description, expected] of [
            ['', 'Create page', null, true],
            ['IMAGES', 'Media library', 'Find images', true],
            ['IMAGES', 'Create page', 'Add content', false],
            ['PAGE', 'Create page', null, true],
            ['missing', 'Media library', 'Find images', false],
        ]) {
            state.query = query;
            assert.equal(state.matches(label, description), expected);
        }
        reset(false);
        assert.equal(state.query, '');
        console.log('local search passed');
        JS, $root->getAttribute('x-data')]);
    $process->mustRun();

    expect(trim($process->getOutput()))->toBe('local search passed');
});
