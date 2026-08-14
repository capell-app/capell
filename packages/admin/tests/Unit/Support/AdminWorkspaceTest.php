<?php

declare(strict_types=1);

use Capell\Admin\Data\AdminWorkspaceItemData;
use Capell\Admin\Enums\AdminWorkspaceEnum;
use Capell\Admin\Enums\ResourceEnum;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Pages\MarketingStudioPage;
use Capell\Admin\Filament\Pages\SiteHealthPage;
use Capell\Admin\Filament\Plugin\CapellAdminPlugin;
use Capell\Admin\Filament\Resources\Pages\PageResource;
use Capell\Admin\Livewire\Header\AdminWorkspaceSwitcher;
use Capell\Admin\Support\Workspace\AdminWorkspaceNavigator;
use Capell\Admin\Support\Workspace\AdminWorkspacePreferenceStore;
use Capell\Admin\Support\Workspace\AdminWorkspaceRegistry;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Auth\User as AuthenticatableUser;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function (): void {
    CapellAdmin::clearWorkspaces();
});

final class AdminWorkspaceTestUser extends AuthenticatableUser
{
    /**
     * @param  list<string>  $roles
     * @param  list<string>  $permissions
     */
    public function __construct(private array $roles = [], private array $permissions = [], private bool $global = false)
    {
        parent::__construct();
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    public function checkPermissionTo(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    public function isGlobalAdmin(): bool
    {
        return $this->global;
    }
}

function workspaceItem(string $key, AdminWorkspaceEnum ...$workspaces): AdminWorkspaceItemData
{
    $workspaces = $workspaces === [] ? [AdminWorkspaceEnum::All] : $workspaces;

    return new AdminWorkspaceItemData(
        key: $key,
        label: ucfirst($key),
        url: '/admin/' . $key,
        workspaces: array_values($workspaces),
        roles: in_array(AdminWorkspaceEnum::All, $workspaces, true)
            ? []
            : array_values(array_map(static fn (AdminWorkspaceEnum $workspace): string => $workspace->value, $workspaces)),
    );
}

it('filters role workspaces before the all-tools surface and preserves permission boundaries', function (): void {
    $registry = new AdminWorkspaceRegistry;
    $registry->register(workspaceItem('editor-tool', AdminWorkspaceEnum::Editor));
    $registry->register(workspaceItem('marketer-tool', AdminWorkspaceEnum::Marketer));
    $registry->register(new AdminWorkspaceItemData(
        key: 'gated-tool',
        label: 'Gated tool',
        url: '/admin/gated-tool',
        workspaces: [AdminWorkspaceEnum::Editor],
        roles: ['editor'],
        permission: 'manage gated tool',
    ));

    $editor = new AdminWorkspaceTestUser(['editor']);
    $editorWithPermission = new AdminWorkspaceTestUser(['editor'], ['manage gated tool']);
    $marketer = new AdminWorkspaceTestUser(['marketer']);

    expect(array_column($registry->visible($editor, AdminWorkspaceEnum::All), 'key'))
        ->toBe(['editor-tool'])
        ->and(array_column($registry->visible($editorWithPermission, AdminWorkspaceEnum::Editor), 'key'))
        ->toBe(['editor-tool', 'gated-tool'])
        ->and(array_column($registry->visible($marketer, AdminWorkspaceEnum::All), 'key'))
        ->toBe(['marketer-tool']);
});

it('registers built-in real Admin destinations alongside package contributions', function (): void {
    $method = new ReflectionMethod(CapellAdminPlugin::class, 'registerBuiltInWorkspaces');
    $method->invoke(CapellAdminPlugin::make());

    CapellAdmin::registerWorkspace(new AdminWorkspaceItemData(
        key: 'package.analytics',
        label: static fn (Authenticatable $actor): string => 'Analytics',
        url: static fn (Authenticatable $actor): string => '/admin/analytics',
        workspaces: [AdminWorkspaceEnum::Marketer],
        roles: ['marketer'],
    ));

    $definitions = CapellAdmin::getWorkspaceDefinitions();

    expect($definitions)->toHaveKeys(['capell.pages', 'capell.marketing-studio', 'capell.site-health', 'package.analytics'])
        ->and($definitions['capell.pages']->label)->toBeInstanceOf(Closure::class)
        ->and($definitions['capell.pages']->url)->toBeInstanceOf(Closure::class)
        ->and($definitions['package.analytics']->label)->toBeInstanceOf(Closure::class)
        ->and($definitions['capell.pages']->roles)->toBe([config('capell.roles.editor')])
        ->and($definitions['capell.marketing-studio']->roles)->toBe([])
        ->and($definitions['capell.site-health']->roles)->toBe([])
        ->and($definitions['capell.pages']->permission)->toBe(ResourceEnum::Page->permission('view_any'))
        ->and($definitions['capell.marketing-studio']->permission)->toBe('View:' . class_basename(MarketingStudioPage::class))
        ->and($definitions['capell.site-health']->permission)->toBe('View:' . class_basename(SiteHealthPage::class));
});

it('uses relative URLs from the configured Filament panel for built-in destinations', function (): void {
    test()->actingAsAdmin();

    $method = new ReflectionMethod(CapellAdminPlugin::class, 'registerBuiltInWorkspaces');
    $method->invoke(CapellAdminPlugin::make());

    $definitions = CapellAdmin::getWorkspaceDefinitions();
    assert($definitions['capell.pages']->url instanceof Closure);
    assert($definitions['capell.marketing-studio']->url instanceof Closure);
    assert($definitions['capell.site-health']->url instanceof Closure);
    $actor = new AdminWorkspaceTestUser(['editor'], [
        ResourceEnum::Page->permission('view_any'),
        'View:' . class_basename(MarketingStudioPage::class),
        'View:' . class_basename(SiteHealthPage::class),
    ]);
    $visible = resolve(AdminWorkspaceRegistry::class)->visible($actor);

    expect(($definitions['capell.pages']->url)($actor))
        ->toBe(PageResource::getUrl('index', isAbsolute: false))
        ->toStartWith('/')
        ->and(($definitions['capell.marketing-studio']->url)($actor))
        ->toBe(MarketingStudioPage::getUrl(isAbsolute: false))
        ->toStartWith('/')
        ->and(($definitions['capell.site-health']->url)($actor))
        ->toBe(SiteHealthPage::getUrl(isAbsolute: false))
        ->toStartWith('/')
        ->and(array_column($visible, 'key'))
        ->toBe(['capell.pages', 'capell.marketing-studio', 'capell.site-health']);
});

it('registers workspace definitions through the manager facade', function (): void {
    CapellAdmin::registerWorkspace(workspaceItem('manager.tool'));

    expect(CapellAdmin::getWorkspaceDefinitions())->toHaveKey('manager.tool');
});

it('keeps package contributions keyed, rejects unsafe destinations, and replaces duplicate keys deterministically', function (): void {
    $registry = new AdminWorkspaceRegistry;
    $registry->register(workspaceItem('package.tool'));
    $registry->register(new AdminWorkspaceItemData(
        key: 'package.tool',
        label: 'Replacement',
        url: '/admin/replacement',
        workspaces: [AdminWorkspaceEnum::All],
    ));
    $registry->register(new AdminWorkspaceItemData(
        key: 'unsafe key',
        label: 'Unsafe',
        url: 'https://example.test',
        workspaces: [AdminWorkspaceEnum::All],
    ));
    $registry->register(new AdminWorkspaceItemData(
        key: 'unsafe-backslash',
        label: 'Unsafe backslash',
        url: '/\\evil.test',
        workspaces: [AdminWorkspaceEnum::All],
    ));

    expect($registry->generation())->toBe(2)
        ->and(array_keys($registry->definitions()))->toBe(['package.tool'])
        ->and($registry->definitions()['package.tool']->label)->toBe('Replacement');
});

it('keeps global all-tools discovery role-aware while still requiring explicit permissions', function (): void {
    $registry = new AdminWorkspaceRegistry;
    $registry->register(workspaceItem('editor-tool', AdminWorkspaceEnum::Editor));
    $registry->register(new AdminWorkspaceItemData(
        key: 'permission-tool',
        label: 'Permission tool',
        url: '/admin/permission-tool',
        workspaces: [AdminWorkspaceEnum::All],
        permission: 'use permission tool',
    ));

    $globalWithoutPermission = new AdminWorkspaceTestUser(global: true);
    $globalWithPermission = new AdminWorkspaceTestUser([], ['use permission tool'], true);

    expect(array_column($registry->visible($globalWithoutPermission), 'key'))
        ->toBe(['editor-tool'])
        ->and(array_column($registry->visible($globalWithPermission), 'key'))
        ->toBe(['editor-tool', 'permission-tool']);
});

it('persists only visible pins and recents per user', function (): void {
    $user = test()->createUser();
    $store = new AdminWorkspacePreferenceStore;

    $store->togglePin($user, 'allowed', ['allowed']);
    $store->togglePin($user, 'denied', ['allowed']);
    $store->recordVisit($user, 'allowed', ['allowed']);
    $store->recordVisit($user, 'denied', ['allowed']);

    expect($store->read($user))
        ->toBe(['pinned' => ['allowed'], 'recent' => ['allowed']]);

    $user->refresh();
    expect(json_decode((string) DB::table('users')->where('id', $user->getKey())->value('admin_workspace_preferences'), true))
        ->toBe(['pinned' => ['allowed'], 'recent' => ['allowed']]);
});

it('intersects stale preferences with the currently visible workspace items', function (): void {
    $user = test()->createUser();
    DB::table('users')->where('id', $user->getKey())->update([
        'admin_workspace_preferences' => json_encode(['pinned' => ['allowed', 'removed'], 'recent' => ['removed', 'allowed']]),
    ]);
    $user->refresh();

    $registry = new AdminWorkspaceRegistry;
    $registry->register(workspaceItem('allowed'));

    $state = (new AdminWorkspaceNavigator($registry, new AdminWorkspacePreferenceStore))
        ->state($user, AdminWorkspaceEnum::All);

    expect($state->pinnedKeys)->toBe(['allowed'])
        ->and($state->recentKeys)->toBe(['allowed']);
});

it('does not allow direct pin or recent mutations for an item outside the visible registry', function (): void {
    $user = test()->createUser();
    $registry = new AdminWorkspaceRegistry;
    $registry->register(workspaceItem('allowed'));
    $navigator = new AdminWorkspaceNavigator($registry, new AdminWorkspacePreferenceStore);

    $navigator->togglePin($user, 'denied', AdminWorkspaceEnum::All);
    $navigator->recordVisit($user, 'denied', AdminWorkspaceEnum::All);

    expect(resolve(AdminWorkspacePreferenceStore::class)->read($user))
        ->toBe(['pinned' => [], 'recent' => []]);
});

it('keeps pinned and recent destinations out of the default tools list', function (): void {
    $user = test()->createUser();
    $registry = new AdminWorkspaceRegistry;
    $registry->register(workspaceItem('pinned'));
    $registry->register(workspaceItem('recent'));
    $registry->register(workspaceItem('plain'));

    $store = new AdminWorkspacePreferenceStore;
    $store->togglePin($user, 'pinned', ['pinned', 'recent', 'plain']);
    $store->recordVisit($user, 'recent', ['pinned', 'recent', 'plain']);

    $navigator = new AdminWorkspaceNavigator($registry, $store);

    expect(array_column($navigator->toolItems($user, AdminWorkspaceEnum::All), 'key'))
        ->toBe(['plain']);
});

it('filters pinned, recent, and tools from one de-duplicated search result set', function (): void {
    test()->actingAsAdmin();
    $user = test()->authenticatedUser();
    $registry = resolve(AdminWorkspaceRegistry::class);

    $registry->register(new AdminWorkspaceItemData(
        key: 'shared',
        label: 'Pinned Match',
        url: '/admin/shared',
        workspaces: [AdminWorkspaceEnum::All],
    ));
    $registry->register(new AdminWorkspaceItemData(
        key: 'recent',
        label: 'Recent Match',
        url: '/admin/recent',
        workspaces: [AdminWorkspaceEnum::All],
    ));
    $registry->register(new AdminWorkspaceItemData(
        key: 'tool',
        label: 'Tool Match',
        url: '/admin/tool',
        workspaces: [AdminWorkspaceEnum::All],
    ));
    $registry->register(new AdminWorkspaceItemData(
        key: 'unrelated',
        label: 'Unrelated Tool',
        url: '/admin/unrelated',
        workspaces: [AdminWorkspaceEnum::All],
    ));

    $store = resolve(AdminWorkspacePreferenceStore::class);
    $store->togglePin($user, 'shared', ['shared', 'recent', 'tool', 'unrelated']);
    $store->recordVisit($user, 'shared', ['shared', 'recent', 'tool', 'unrelated']);
    $store->recordVisit($user, 'recent', ['shared', 'recent', 'tool', 'unrelated']);

    $component = Livewire::test(AdminWorkspaceSwitcher::class)
        ->set('search', 'match')
        ->instance();
    $pinned = $component->pinnedItems();
    $recent = $component->recentItems();
    $tools = $component->toolItems();
    $keys = [
        ...array_column($pinned, 'key'),
        ...array_column($recent, 'key'),
        ...array_column($tools, 'key'),
    ];

    expect(array_column($pinned, 'key'))->toBe(['shared'])
        ->and(array_column($recent, 'key'))->toBe(['recent'])
        ->and(array_column($tools, 'key'))->toBe(['tool'])
        ->and($keys)->toHaveCount(count(array_unique($keys)))
        ->and(array_column($component->items(), 'key'))->toBe(['recent', 'shared', 'tool'])
        ->and(array_column($component->items(), 'key'))->not->toContain('unrelated');
});

it('resolves deferred values before filtering and fails closed for null or faulty actors', function (): void {
    $registry = new AdminWorkspaceRegistry;
    $registry->register(new AdminWorkspaceItemData(
        key: 'deferred',
        label: static fn (Authenticatable $actor): string => 'Deferred label',
        url: static fn (Authenticatable $actor): string => '/admin/deferred',
        workspaces: [AdminWorkspaceEnum::All],
    ));
    $registry->register(new AdminWorkspaceItemData(
        key: 'throwing',
        label: static fn (Authenticatable $actor): string => throw new RuntimeException('label failed'),
        url: '/admin/throwing',
        workspaces: [AdminWorkspaceEnum::All],
    ));
    $registry->register(new AdminWorkspaceItemData(
        key: 'unsafe',
        label: 'Unsafe',
        url: static fn (Authenticatable $actor): string => '/\\evil.test',
        workspaces: [AdminWorkspaceEnum::All],
    ));
    $registry->register(new AdminWorkspaceItemData(
        key: 'role-fault',
        label: 'Role fault',
        url: '/admin/role-fault',
        workspaces: [AdminWorkspaceEnum::All],
        roles: ['editor'],
    ));

    $throwingRoleActor = new class extends AuthenticatableUser
    {
        public function hasRole(string $role): bool
        {
            throw new RuntimeException('role failed');
        }
    };

    expect(array_column($registry->visible(new AdminWorkspaceTestUser), 'key'))
        ->toBe(['deferred'])
        ->and($registry->visible(null))->toBe([])
        ->and(array_column($registry->visible($throwingRoleActor), 'key'))->toBe(['deferred']);
});
