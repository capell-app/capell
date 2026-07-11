<?php

declare(strict_types=1);

use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Tests\Fixtures\Models\User;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Collection as SupportCollection;
use Spatie\Permission\Models\Role;

/**
 * @param  SupportCollection<int, int>  $assignedSiteIds
 */
function createScopedUserForPageTreeControllerTest(SupportCollection $assignedSiteIds, bool $isGlobalAdmin = false, bool $canViewPages = true): Authenticatable
{
    $user = new class extends Authenticatable implements FilamentUser
    {
        /** @use HasFactory<Factory<static>> */
        use HasFactory;

        /** @var SupportCollection<int, int> */
        public SupportCollection $assignedSiteIds;

        public bool $globalAdmin = false;

        public bool $canViewPages = true;

        protected $table = 'users';

        public function canAccessPanel(Panel $panel): bool
        {
            return true;
        }

        public function isGlobalAdmin(): bool
        {
            return $this->globalAdmin;
        }

        public function hasRole(string $role): bool
        {
            return $this->globalAdmin;
        }

        public function checkPermissionTo(string $permission): bool
        {
            return $this->canViewPages;
        }

        public function getMorphClass(): string
        {
            return User::class;
        }

        /** @return SupportCollection<int, int> */
        public function getAssignedSiteIds(): SupportCollection
        {
            return $this->assignedSiteIds;
        }
    };

    $user->forceFill([
        'name' => 'Scoped User',
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('password'),
    ]);
    $user->assignedSiteIds = $assignedSiteIds;
    $user->globalAdmin = $isGlobalAdmin;
    $user->canViewPages = $canViewPages;

    return $user;
}

it('limits page tree results to assigned sites for non-global users', function (): void {
    $assignedSite = Site::factory()->withTranslations()->create();
    $otherSite = Site::factory()->withTranslations()->create();
    $assignedPage = Page::factory()->recycle($assignedSite)->create(['name' => 'Assigned Page']);
    Page::factory()->recycle($otherSite)->create(['name' => 'Other Page']);

    test()->actingAs(createScopedUserForPageTreeControllerTest(collect([$assignedSite->getKey()])));

    test()->getJson(route('capell-admin.api.page-tree'))
        ->assertOk()
        ->assertJsonPath('data.0.id', $assignedPage->getKey())
        ->assertJsonCount(1, 'data');
});

it('rejects authenticated users without admin panel access', function (): void {
    $user = new class extends Authenticatable implements FilamentUser
    {
        /** @use HasFactory<Factory<static>> */
        use HasFactory;

        protected $table = 'users';

        public function canAccessPanel(Panel $panel): bool
        {
            return false;
        }
    };

    $user->forceFill([
        'name' => 'Non Admin User',
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('password'),
    ]);

    test()->actingAs($user);

    test()->getJson(route('capell-admin.api.page-tree'))
        ->assertForbidden();
});

it('rejects admin panel users without page view permission', function (): void {
    $assignedSite = Site::factory()->withTranslations()->create();
    Page::factory()->recycle($assignedSite)->create();

    test()->actingAs(createScopedUserForPageTreeControllerTest(collect([$assignedSite->getKey()]), canViewPages: false));

    test()->getJson(route('capell-admin.api.page-tree'))
        ->assertForbidden();
});

it('denies page tree results for unassigned site filters', function (): void {
    $assignedSite = Site::factory()->withTranslations()->create();
    $otherSite = Site::factory()->withTranslations()->create();
    Page::factory()->recycle($otherSite)->create();

    test()->actingAs(createScopedUserForPageTreeControllerTest(collect([$assignedSite->getKey()])));

    test()->getJson(route('capell-admin.api.page-tree', ['site_id' => $otherSite->getKey()]))
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('hides role-restricted pages from users without an allowed role', function (): void {
    $assignedSite = Site::factory()->withTranslations()->create();
    $type = Blueprint::factory()->page()->create();
    $role = Role::create(['name' => 'restricted-page-tree-viewer', 'guard_name' => 'web']);
    $type->roleRestrictions()->create(['role_id' => $role->getKey()]);

    $restrictedPage = Page::factory()
        ->recycle($assignedSite)
        ->create([
            'blueprint_id' => $type->getKey(),
            'name' => 'Restricted Page',
        ]);

    $user = createScopedUserForPageTreeControllerTest(collect([$assignedSite->getKey()]));

    expect($restrictedPage->fresh(['site', 'blueprint.roleRestrictions'])->isAccessibleByUser($user))->toBeFalse();

    test()->actingAs($user);

    test()->getJson(route('capell-admin.api.page-tree'))
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('scopes page tree child existence flags', function (): void {
    $assignedSite = Site::factory()->withTranslations()->create();
    $otherSite = Site::factory()->withTranslations()->create();
    $assignedPage = Page::factory()->recycle($assignedSite)->create();
    Page::factory()->recycle($otherSite)->create(['parent_id' => $assignedPage->getKey()]);

    test()->actingAs(createScopedUserForPageTreeControllerTest(collect([$assignedSite->getKey()])));

    test()->getJson(route('capell-admin.api.page-tree'))
        ->assertOk()
        ->assertJsonPath('data.0.id', $assignedPage->getKey())
        ->assertJsonPath('data.0.has_children', false);
});

it('ignores role-restricted children when calculating child existence flags', function (): void {
    $assignedSite = Site::factory()->withTranslations()->create();
    $parentPage = Page::factory()->recycle($assignedSite)->create();
    $type = Blueprint::factory()->page()->create();
    $role = Role::create(['name' => 'restricted-page-tree-child-viewer', 'guard_name' => 'web']);
    $type->roleRestrictions()->create(['role_id' => $role->getKey()]);

    $restrictedChild = Page::factory()
        ->recycle($assignedSite)
        ->create([
            'blueprint_id' => $type->getKey(),
            'parent_id' => $parentPage->getKey(),
        ]);

    $user = createScopedUserForPageTreeControllerTest(collect([$assignedSite->getKey()]));

    expect($restrictedChild->fresh(['site', 'blueprint.roleRestrictions'])->isAccessibleByUser($user))->toBeFalse();

    test()->actingAs($user);

    test()->getJson(route('capell-admin.api.page-tree'))
        ->assertOk()
        ->assertJsonPath('data.0.id', $parentPage->getKey())
        ->assertJsonPath('data.0.has_children', false);
});

it('supports paginated root loading without changing the legacy data payload', function (): void {
    $assignedSite = Site::factory()->withTranslations()->create();

    for ($index = 1; $index <= 11; $index++) {
        Page::factory()
            ->recycle($assignedSite)
            ->create([
                'name' => sprintf('Paginated root %02d', $index),
                'order' => $index,
            ]);
    }

    test()->actingAs(createScopedUserForPageTreeControllerTest(collect([$assignedSite->getKey()])));

    test()->getJson(route('capell-admin.api.page-tree', [
        'mode' => 'site-root',
        'site_id' => $assignedSite->getKey(),
        'page' => 1,
        'per_page' => 10,
    ]))
        ->assertOk()
        ->assertJsonCount(10, 'data')
        ->assertJsonPath('data.0.name', 'Paginated root 01')
        ->assertJsonPath('meta.has_more', true)
        ->assertJsonPath('meta.next_page', 2);
});
