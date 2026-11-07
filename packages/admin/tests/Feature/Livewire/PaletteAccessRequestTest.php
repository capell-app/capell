<?php

declare(strict_types=1);

use Capell\Admin\Filament\Pages\SettingsPage;
use Capell\Admin\Http\Middleware\SetSitePermissionScope;
use Capell\Admin\Livewire\GlobalCommandPalette;
use Capell\Admin\Support\PaletteAccess;
use Capell\Admin\Support\PaletteRequestState;
use Capell\Admin\Tests\Fixtures\Models\RealSiteScopedAdminResourceUser;
use Capell\Admin\Tests\Support\SiteScopedUserFactory;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Support\Permissions\PermissionTeamContext;
use Filament\Facades\Filament;
use Filament\Panel;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Container\Container;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Route;
use Livewire\Component;
use Livewire\EventBus;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleRequests\HandleRequests;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    config(['permission.teams' => true, 'auth.providers.users.model' => RealSiteScopedAdminResourceUser::class]);
    $registrar = resolve(PermissionRegistrar::class);
    $registrar->teams = true;
    $registrar->forgetCachedPermissions();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire::addPersistentMiddleware([SetSitePermissionScope::class]);
    Filament::bootCurrentPanel();

    Route::model('paletteSite', Site::class);
    Route::get('/palette-site/{paletteSite}', static fn (): string => FilamentView::renderHook(PanelsRenderHook::BODY_START)->toHtml())
        ->middleware(['web', SubstituteBindings::class, SetSitePermissionScope::class]);
    Route::model('paletteRecord', Page::class);
    Route::get('/palette-record/{paletteRecord}', static fn (): string => FilamentView::renderHook(PanelsRenderHook::BODY_START)->toHtml())
        ->middleware(['web', SubstituteBindings::class, SetSitePermissionScope::class]);
});

afterEach(function (): void {
    DB::disableQueryLog();
    DB::flushQueryLog();
    auth()->logout();
    $registrar = resolve(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId(null);
    $registrar->teams = false;
    $registrar->forgetCachedPermissions();
    config(['permission.teams' => false]);
});

function paletteSnapshot(string $html): string
{
    preg_match('/wire:snapshot="([^"]+)"/', $html, $matches);

    return html_entity_decode($matches[1] ?? throw new RuntimeException('Missing palette snapshot.'), ENT_QUOTES);
}

function palettePermissionQueryCount(): int
{
    return collect(DB::getQueryLog())->filter(static fn (array $query): bool => preg_match(
        '/\b(sites|roles|permissions|model_has_roles|model_has_permissions|role_has_permissions)\b/',
        $query['query'],
    ) === 1)->count();
}

it('keeps the route bound site through opening and closing on real Livewire updates', function (string $recordType): void {
    $selectedSite = Site::factory()->createOne();
    $recordSite = Site::factory()->createOne();
    $user = SiteScopedUserFactory::create($recordSite, ['View:SettingsPage']);
    PermissionTeamContext::run($selectedSite->getKey(), function () use ($user, $selectedSite): void {
        Role::findOrCreate('selected-site-member', 'web');
        $user->assignRoleForSite($selectedSite, 'selected-site-member');
    }, $user);
    $this->actingAs($user)->withSession(['capell.current_site_id' => $selectedSite->getKey()]);
    $url = 'href="' . SettingsPage::getUrl() . '"';
    $path = $recordType === 'site'
        ? '/palette-site/' . $recordSite->getKey()
        : '/palette-record/' . Page::factory()->createOne(['site_id' => $recordSite->getKey()])->getKey();
    $html = (string) $this->get($path)->assertOk()->assertSee($url, false)
        ->assertSessionHas('capell.current_site_id', $selectedSite->getKey())->getContent();
    $snapshot = paletteSnapshot($html);

    foreach ([true, false, true] as $open) {
        app()->forgetScopedInstances();
        Livewire::flushState();
        $response = $this->postJson(resolve(HandleRequests::class)->getUpdateUri(), ['components' => [[
            'snapshot' => $snapshot,
            'updates' => ['open' => $open],
            'calls' => [],
        ]]], ['X-Livewire' => 'true'])->assertOk();
        expect($response->json('components.0.effects.html'))->toContain($url);
        $snapshot = $response->json('components.0.snapshot');
        expect(resolve(PermissionRegistrar::class)->getPermissionsTeamId())->toBeNull();
    }
})->with(['page', 'site']);

it('performs one bounded permission computation for the shared full page hook and each palette update', function (bool $update): void {
    $site = Site::factory()->createOne();
    $user = SiteScopedUserFactory::create($site, ['View:SettingsPage']);
    $this->actingAs($user)->withSession(['capell.current_site_id' => $site->getKey()]);
    $record = Page::factory()->createOne(['site_id' => $site->getKey()]);
    resolve(PaletteRequestState::class)->recordTeamId($user, $site->getKey());
    resolve(PaletteAccess::class)->forRender();
    DB::enableQueryLog();
    DB::flushQueryLog();
    // Baseline: independently authorising the keyboard handler, command list
    // and shortcut help would repeat the same database work three times.
    for ($consumer = 0; $consumer < 3; $consumer++) {
        resolve(PaletteAccess::class)->forRender();
    }

    $threeConsumerBaseline = palettePermissionQueryCount();
    DB::disableQueryLog();

    $snapshot = null;
    if ($update) {
        $snapshot = paletteSnapshot((string) $this->get('/palette-record/' . $record->getKey())->assertOk()->getContent());
        app()->forgetScopedInstances();
        Livewire::flushState();
    }

    view()->composer('capell-admin::components.palette-tools', static function (): void {
        DB::enableQueryLog();
        DB::flushQueryLog();
    });
    $stopHydrate = resolve(EventBus::class)->on('hydrate', static function (Component $component) use ($update): void {
        if ($update && $component instanceof GlobalCommandPalette) {
            DB::enableQueryLog();
            DB::flushQueryLog();
        }
    });
    $counts = [];
    $stop = resolve(EventBus::class)->on('render', function (Component $component) use (&$counts): ?Closure {
        if (! $component instanceof GlobalCommandPalette) {
            return null;
        }

        $before = palettePermissionQueryCount();

        return function () use (&$counts, $before): void {
            $counts = [$before, palettePermissionQueryCount()];
        };
    });
    try {
        if ($update) {
            $this->postJson(resolve(HandleRequests::class)->getUpdateUri(), ['components' => [[
                'snapshot' => $snapshot, 'updates' => ['open' => true], 'calls' => [],
            ]]], ['X-Livewire' => 'true'])->assertOk();
        } else {
            $this->get('/palette-record/' . $record->getKey())->assertOk()->assertSee('capellKeyboardShortcuts');
        }

        expect($counts)->toHaveCount(2)
            ->and($counts[1])->toBeGreaterThan(0)->toBeLessThanOrEqual(7)
            ->and($counts[1] * 3)->toBeLessThanOrEqual($threeConsumerBaseline);
        if (! $update) {
            // Mount and the two palette template consumers add no grant queries.
            expect($counts[1])->toBe($counts[0]);
        }
    } finally {
        $stop();
        $stopHydrate();
    }
})->with(['full page' => false, 'palette update' => true]);

it('forgets recorded route context between sequential worker requests', function (): void {
    $allowedSite = Site::factory()->createOne();
    $deniedSite = Site::factory()->createOne();
    $user = SiteScopedUserFactory::create($allowedSite, ['View:SettingsPage']);
    $this->actingAs($user);
    $firstState = resolve(PaletteRequestState::class);
    $url = 'href="' . SettingsPage::getUrl() . '"';
    $this->get('/palette-record/' . Page::factory()->createOne(['site_id' => $allowedSite->getKey()])->getKey())->assertOk()->assertSee($url, false);

    app()->forgetScopedInstances();
    Livewire::flushState();
    expect(resolve(PaletteRequestState::class))->not->toBe($firstState);
    $this->get('/palette-record/' . Page::factory()->createOne(['site_id' => $deniedSite->getKey()])->getKey())->assertOk()->assertDontSee($url, false);
});

it('authorises each actor panel and resolved team independently', function (): void {
    $allowedSite = Site::factory()->createOne();
    $deniedSite = Site::factory()->createOne();
    $allowedRecord = Page::factory()->createOne(['site_id' => $allowedSite->getKey()]);
    $deniedRecord = Page::factory()->createOne(['site_id' => $deniedSite->getKey()]);
    $user = SiteScopedUserFactory::create($allowedSite, ['View:SettingsPage']);
    $deniedUser = SiteScopedUserFactory::create($allowedSite);
    $url = 'href="' . SettingsPage::getUrl() . '"';

    $this->actingAs($user);
    $this->get('/palette-record/' . $allowedRecord->getKey())->assertOk()->assertSee($url, false);
    $this->get('/palette-record/' . $deniedRecord->getKey())->assertOk()->assertDontSee($url, false);
    $this->get('/palette-record/' . $allowedRecord->getKey())->assertOk()->assertSee($url, false);

    $this->actingAs($deniedUser);
    $this->get('/palette-record/' . $allowedRecord->getKey())->assertOk()->assertDontSee($url, false);

    $this->actingAs($user);
    Filament::setCurrentPanel(Panel::make()->id('empty')->authGuard('web'));
    expect(resolve(PaletteAccess::class)->coreCommands())->toBe([]);
});

it('drops revoked commands and shortcuts within the same request', function (string $reset): void {
    $site = Site::factory()->createOne();
    $user = SiteScopedUserFactory::create($site, ['View:SettingsPage']);
    $this->actingAs($user);
    request()->setUserResolver(fn (): RealSiteScopedAdminResourceUser => $user);
    $registrar = resolve(PermissionRegistrar::class);
    // An active middleware scope also leaves loaded relations available to consumers.
    $registrar->setPermissionsTeamId($site->getKey());

    $state = resolve(PaletteRequestState::class);
    $url = SettingsPage::getUrl();
    $label = (string) __('capell-admin::palette.settings');
    $render = fn (): string => Livewire::test(GlobalCommandPalette::class)->html();
    expect($render())->toContain($url)->toContain($label);
    expect(view('capell-admin::components.palette-tools')->render())->toContain('settings');

    DB::table('model_has_permissions')->where('model_id', $user->getKey())
        ->where('model_type', $user->getMorphClass())->where('team_id', $site->getKey())->delete();
    match ($reset) {
        'registrar' => $registrar->forgetCachedPermissions(),
        'roles' => $user->unsetRelation('roles'),
        'permissions' => $user->unsetRelation('permissions'),
        'all relations' => $user->unsetRelations(),
        default => throw new InvalidArgumentException('Unknown permission reset: ' . $reset),
    };
    // Match the permission-reset contract after a registrar reset too.
    if ($reset === 'registrar') {
        $user->unsetRelation('roles')->unsetRelation('permissions');
    }

    expect(resolve(PaletteRequestState::class))->toBe($state);
    expect($render())->not->toContain($url)->not->toContain($label);
    expect(view('capell-admin::components.palette-tools')->render())->not->toContain($url)->not->toContain('Go to Settings');
})->with(['registrar', 'roles', 'permissions', 'all relations']);

it('rechecks access after a registrar reset', function (): void {
    $site = Site::factory()->createOne();
    $user = SiteScopedUserFactory::create($site, ['View:SettingsPage']);
    $this->actingAs($user);
    request()->setUserResolver(fn (): RealSiteScopedAdminResourceUser => $user);
    $access = resolve(PaletteAccess::class);
    $state = resolve(PaletteRequestState::class);
    expect($access->coreCommands())->toHaveKey('capell.settings');
    DB::table('model_has_permissions')->where('model_id', $user->getKey())
        ->where('model_type', $user->getMorphClass())->where('team_id', $site->getKey())->delete();
    resolve(PermissionRegistrar::class)->forgetCachedPermissions();
    expect(resolve(PaletteRequestState::class))->toBe($state);
    expect($access->coreCommands())->not->toHaveKey('capell.settings');
});

it('rechecks access after relation resets with fallback team resolution', function (string $reset): void {
    $site = Site::factory()->createOne();
    $user = SiteScopedUserFactory::create($site, ['View:SettingsPage']);
    $this->actingAs($user);
    request()->setUserResolver(fn (): RealSiteScopedAdminResourceUser => $user);
    $access = resolve(PaletteAccess::class);
    expect($access->coreCommands())->toHaveKey('capell.settings');
    DB::table('model_has_permissions')->where('model_id', $user->getKey())
        ->where('model_type', $user->getMorphClass())->where('team_id', $site->getKey())->delete();
    if ($reset === 'all relations') {
        $user->unsetRelations();
    } else {
        $user->unsetRelation($reset);
    }

    expect($access->coreCommands())->not->toHaveKey('capell.settings');
})->with(['roles', 'permissions', 'all relations']);

it('resolves the switched actors own team without another middleware pass', function (): void {
    $firstSite = Site::factory()->createOne();
    $secondSite = Site::factory()->createOne();
    $first = SiteScopedUserFactory::create($firstSite, ['View:SettingsPage']);
    $second = SiteScopedUserFactory::create($secondSite, ['View:SettingsPage']);
    $state = resolve(PaletteRequestState::class);
    $access = resolve(PaletteAccess::class);
    $this->actingAs($first);
    request()->setUserResolver(fn (): RealSiteScopedAdminResourceUser => $first);
    expect($access->coreCommands())->toHaveKey('capell.settings');

    $this->actingAs($second);
    request()->setUserResolver(fn (): RealSiteScopedAdminResourceUser => $second);
    expect(resolve(PaletteRequestState::class))->toBe($state);
    expect($access->coreCommands())->toHaveKey('capell.settings');
});

it('drops commands after a role is revoked without flushing the registrar', function (): void {
    $site = Site::factory()->createOne();
    $user = SiteScopedUserFactory::create($site);
    PermissionTeamContext::run($site->getKey(), function (): void {
        $role = Role::findByName('test-site-member', 'web');
        $role->givePermissionTo(Permission::findOrCreate('View:SettingsPage', 'web'));
    }, $user);
    $this->actingAs($user);
    request()->setUserResolver(fn (): RealSiteScopedAdminResourceUser => $user);
    $access = resolve(PaletteAccess::class);
    expect($access->coreCommands())->toHaveKey('capell.settings');
    $user->removeRoleForSite($site, 'test-site-member');
    expect($access->coreCommands())->not->toHaveKey('capell.settings');
    expect(array_column($access->forRender()->shortcuts, 'target'))->not->toContain('settings');
});

it('rechecks direct pivot revocation between renders without cache signals', function (string $mutation): void {
    $site = Site::factory()->createOne();
    $user = SiteScopedUserFactory::create($site, str_starts_with($mutation, 'permission') ? ['View:SettingsPage'] : []);
    $registrar = resolve(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($site->getKey());

    $role = Role::findByName('test-site-member', 'web');
    if (! str_starts_with($mutation, 'permission')) {
        $role->givePermissionTo(Permission::findOrCreate('View:SettingsPage', 'web'));
    }

    $this->actingAs($user);
    request()->setUserResolver(fn (): RealSiteScopedAdminResourceUser => $user);
    $url = SettingsPage::getUrl();
    $render = fn (): string => Livewire::test(GlobalCommandPalette::class)->html();
    expect($render())->toContain($url)->toContain('Go to Settings');
    match ($mutation) {
        'roles detach' => $user->roles()->detach(),
        'roles sync' => $user->roles()->sync([]),
        'permissions detach' => $user->permissions()->detach(),
        'permissions sync' => $user->permissions()->sync([]),
        'role permissions detach' => $role->permissions()->detach(),
        'role permissions sync' => $role->permissions()->sync([]),
        default => throw new InvalidArgumentException($mutation),
    };
    expect($render())->not->toContain($url)->not->toContain('Go to settings')->not->toContain('Go to Settings');
})->with(['roles detach', 'roles sync', 'permissions detach', 'permissions sync', 'role permissions detach', 'role permissions sync']);

it('preserves the replayed route team after a registrar reset before render', function (): void {
    $selectedSite = Site::factory()->createOne();
    $recordSite = Site::factory()->createOne();
    $user = SiteScopedUserFactory::create($selectedSite, ['View:SettingsPage']);
    PermissionTeamContext::run($recordSite->getKey(), function () use ($user, $recordSite): void {
        Role::findOrCreate('record-member', 'web');
        $user->assignRoleForSite($recordSite, 'record-member');
    }, $user);
    $this->actingAs($user)->withSession(['capell.current_site_id' => $selectedSite->getKey()]);
    $html = (string) $this->get('/palette-record/' . Page::factory()->createOne(['site_id' => $recordSite->getKey()])->getKey())->assertOk()->getContent();
    app()->forgetScopedInstances();
    Livewire::flushState();
    $stop = resolve(EventBus::class)->on('hydrate', function (Component $component): void {
        if ($component instanceof GlobalCommandPalette) {
            resolve(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    });
    try {
        $response = $this->postJson(resolve(HandleRequests::class)->getUpdateUri(), ['components' => [[
            'snapshot' => paletteSnapshot($html), 'updates' => ['open' => true], 'calls' => [],
        ]]], ['X-Livewire' => 'true'])->assertOk();
        expect($response->json('components.0.effects.html'))->not->toContain(SettingsPage::getUrl())->not->toContain('Go to Settings');
    } finally {
        $stop();
    }
});

it('rechecks access in an Octane sandbox whose dispatcher captured the root container', function (): void {
    $site = Site::factory()->createOne();
    $user = SiteScopedUserFactory::create($site, ['View:SettingsPage']);
    $this->actingAs($user);
    request()->setUserResolver(fn (): RealSiteScopedAdminResourceUser => $user);
    $root = app();
    $rootState = resolve(PaletteRequestState::class);
    $sandbox = clone $root;
    $sandbox->forgetScopedInstances();
    Container::setInstance($sandbox);
    Facade::setFacadeApplication($sandbox);
    try {
        $state = resolve(PaletteRequestState::class);
        expect($state)->not->toBe($rootState);
        $state->recordTeamId($user, $site->getKey());
        $access = resolve(PaletteAccess::class);
        expect($access->coreCommands())->toHaveKey('capell.settings');
        resolve(PermissionRegistrar::class)->setPermissionsTeamId($site->getKey());
        $user->permissions()->detach();
        resolve(PermissionRegistrar::class)->setPermissionsTeamId(null);
        resolve(PermissionRegistrar::class)->forgetCachedPermissions();
        expect($access->coreCommands())->not->toHaveKey('capell.settings');
        expect($state->resolvedTeamId($user, static fn (): ?int => null))->toBe($site->getKey());
    } finally {
        Container::setInstance($root);
        Facade::setFacadeApplication($root);
    }
});

it('keeps freshly loaded permissions available to navigation in the unchanged team', function (): void {
    $site = Site::factory()->createOne();
    $user = SiteScopedUserFactory::create($site, ['View:SettingsPage']);
    $this->actingAs($user);
    request()->setUserResolver(fn (): RealSiteScopedAdminResourceUser => $user);
    resolve(PermissionRegistrar::class)->setPermissionsTeamId($site->getKey());
    expect(resolve(PaletteAccess::class)->coreCommands())->toHaveKey('capell.settings');

    DB::enableQueryLog();
    DB::flushQueryLog();
    expect(SettingsPage::canAccess())->toBeTrue();
    expect(palettePermissionQueryCount())->toBe(0);

    // The next access computation still observes unsignalled direct revocation.
    $user->permissions()->detach();
    expect(resolve(PaletteAccess::class)->coreCommands())->not->toHaveKey('capell.settings');
});
