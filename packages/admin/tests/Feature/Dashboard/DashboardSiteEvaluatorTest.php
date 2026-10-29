<?php

declare(strict_types=1);

use Capell\Admin\Actions\Dashboard\BuildContentStatusAction;
use Capell\Admin\Actions\Dashboard\CanViewDashboardItemAction;
use Capell\Admin\Actions\Dashboard\ConstrainDashboardPageLanguageAction;
use Capell\Admin\Contracts\Dashboard\DashboardPageLanguageMatcher;
use Capell\Admin\Support\Dashboard\DashboardSiteEvaluator;
use Capell\Admin\Tests\Fixtures\Models\RealSiteScopedAdminResourceUser;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;
use Capell\Core\Support\Permissions\SiteAccess;
use Capell\Core\Support\Publishing\PublishSentinel;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    config(['permission.teams' => true]);
    resolve(PermissionRegistrar::class)->teams = true;
    resolve(PermissionRegistrar::class)->forgetCachedPermissions();
});

afterEach(function (): void {
    resolve(PermissionRegistrar::class)->setPermissionsTeamId(null);
    resolve(PermissionRegistrar::class)->teams = false;
    resolve(PermissionRegistrar::class)->forgetCachedPermissions();
    config(['permission.teams' => false]);
});

it('evaluates each site with its own permissions and reflects revocation', function (): void {
    $first = Site::factory()->createOne();
    $second = Site::factory()->createOne();
    $foreign = Site::factory()->createOne();
    $actor = RealSiteScopedAdminResourceUser::query()->create(['name' => 'Editor', 'email' => fake()->unique()->safeEmail(), 'password' => bcrypt('password')]);
    $registrar = resolve(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($first->id);

    $firstRole = Role::findOrCreate('dashboard-first', 'web');
    $firstRole->givePermissionTo(Permission::findOrCreate('ViewAny:Page', 'web'));

    $actor->assignRoleForSite($first, 'dashboard-first');
    $registrar->setPermissionsTeamId($second->id);
    Role::findOrCreate('dashboard-second', 'web');
    $actor->assignRoleForSite($second, 'dashboard-second');
    $firstPage = Page::factory()->createOne(['site_id' => $first->id, 'visible_from' => PublishSentinel::draftValue()]);
    $secondPage = Page::factory()->createOne(['site_id' => $second->id, 'visible_from' => PublishSentinel::draftValue()]);
    Page::factory()->createOne(['site_id' => $foreign->id, 'visible_from' => PublishSentinel::draftValue()]);
    $registrar->setPermissionsTeamId($second->id);

    $counts = resolve(BuildContentStatusAction::class);
    expect($counts->handle($actor, null, null)['draft'])->toBe(1)
        ->and(resolve(CanViewDashboardItemAction::class)->handle($actor, $first->id, null, $firstPage->id))->toBeTrue()
        ->and(resolve(CanViewDashboardItemAction::class)->handle($actor, $second->id, null, $secondPage->id))->toBeFalse()
        ->and($counts->handle($actor, $foreign->id, null)['draft'])->toBe(0)
        ->and($registrar->getPermissionsTeamId())->toBe($second->id);

    $actor->removeRoleForSite($first, 'dashboard-first');
    expect($counts->handle($actor, null, null)['draft'])->toBe(0)
        ->and(resolve(CanViewDashboardItemAction::class)->handle($actor, $first->id, null, $firstPage->id))->toBeFalse();
});

it('restores the previous team and clears loaded permission relations on exception', function (): void {
    $site = Site::factory()->createOne();
    $actor = new RealSiteScopedAdminResourceUser;
    $registrar = resolve(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId(8123);

    $actor->setRelation('roles', collect());
    $actor->setRelation('permissions', collect());
    try {
        resolve(DashboardSiteEvaluator::class)->run($actor, $site->id, function () use ($registrar, $site): void {
            expect($registrar->getPermissionsTeamId())->toBe($site->id);
            throw new RuntimeException('test');
        });
    } catch (RuntimeException $runtimeException) {
        expect($runtimeException->getMessage())->toBe('test');
    }

    expect($registrar->getPermissionsTeamId())->toBe(8123)
        ->and($actor->relationLoaded('roles'))->toBeFalse()
        ->and($actor->relationLoaded('permissions'))->toBeFalse();
});

it('keeps the active team for global administrators and restores it when teams are disabled', function (): void {
    $this->actingAsAdmin();
    $actor = $this->authenticatedUser();
    $site = Site::factory()->createOne();
    $registrar = resolve(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId(1234);

    $evaluator = resolve(DashboardSiteEvaluator::class);
    expect($evaluator->siteIds($actor, $site->id))->toBe([$site->id])
        ->and($evaluator->run($actor, $site->id, fn (): mixed => $registrar->getPermissionsTeamId()))->toBe(1234);
    $registrar->teams = false;
    expect($evaluator->run($actor, $site->id, fn (): mixed => $registrar->getPermissionsTeamId()))->toBe(1234)
        ->and($registrar->getPermissionsTeamId())->toBe(1234);
});

it('keeps language filtering within explicit site access without losing authorised workspace pages', function (): void {
    $first = Site::factory()->createOne();
    $second = Site::factory()->createOne();
    $foreign = Site::factory()->createOne();
    $actor = RealSiteScopedAdminResourceUser::query()->create(['name' => 'Editor', 'email' => fake()->unique()->safeEmail(), 'password' => bcrypt('password')]);
    $registrar = resolve(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId(null);
    Role::findOrCreate('language-dashboard-editor', 'web');
    $actor->assignRoleForSite($first, 'language-dashboard-editor');
    $actor->assignRoleForSite($second, 'language-dashboard-editor');

    $registrar->setPermissionsTeamId($first->id);
    test()->actingAs($actor);

    Schema::table('pages', function (Blueprint $table): void {
        $table->unsignedBigInteger('workspace_id')->default(0);
    });
    Schema::table('translations', function (Blueprint $table): void {
        $table->unsignedBigInteger('workspace_id')->default(0);
    });
    $language = Language::factory()->createOne(['code' => 'fr-workspace-dashboard']);
    $pages = [];
    foreach ([$first, $second, $foreign] as $site) {
        $page = Page::factory()->createOne(['site_id' => $site->id]);
        $page->forceFill(['workspace_id' => 42])->save();
        Translation::withoutEvents(fn () => Translation::factory()->translatable($page)->createOne(['language_id' => $language->id, 'workspace_id' => 42]));
        $pages[] = $page;
    }

    $originalScopes = Page::getAllGlobalScopes();
    $originalTranslationScopes = Translation::getAllGlobalScopes();
    Page::addGlobalScope('dashboard-workspace-language-test', fn (Builder $query) => $query->where('workspace_id', 0));
    Translation::addGlobalScope('dashboard-workspace-language-test', fn (Builder $query) => $query->where('workspace_id', 0));
    try {
        $action = resolve(ConstrainDashboardPageLanguageAction::class);
        $allAccess = SiteAccess::forActor($actor, acrossAssignedSites: true);
        $allPages = $allAccess->scope(Page::query()->withoutGlobalScopes())->whereNull('deleted_at')->where('workspace_id', 42);
        expect($action->handle($allPages, $allAccess, $language->code)->orderBy('id')->pluck('id')->all())
            ->toBe([$pages[0]->id, $pages[1]->id]);

        $currentAccess = SiteAccess::forActor($actor);
        $currentPages = $currentAccess->scope(Page::query()->withoutGlobalScopes())->whereNull('deleted_at')->where('workspace_id', 42);
        expect($action->handle($currentPages, $currentAccess, $language->code)->pluck('id')->all())->toBe([$pages[0]->id])
            ->and($registrar->getPermissionsTeamId())->toBe($first->id);
        $unknownLanguage = $allAccess->scope(Page::query()->withoutGlobalScopes())->whereNull('deleted_at')->where('workspace_id', 42);
        expect($action->handle($unknownLanguage, $allAccess, 'unavailable-language')->exists())->toBeFalse();
    } finally {
        Page::setAllGlobalScopes($originalScopes);
        Translation::setAllGlobalScopes($originalTranslationScopes);
    }
});

it('consults effective language matching only after site and page authorisation', function (): void {
    $allowed = Site::factory()->createOne();
    $denied = Site::factory()->createOne();
    $foreign = Site::factory()->createOne();
    $actor = RealSiteScopedAdminResourceUser::query()->create(['name' => 'Editor', 'email' => fake()->unique()->safeEmail(), 'password' => bcrypt('password')]);
    $registrar = resolve(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($allowed->id);

    $role = Role::findOrCreate('language-boundary-author', 'web');
    $role->givePermissionTo(Permission::findOrCreate('ViewAny:Page', 'web'));

    $actor->assignRoleForSite($allowed, 'language-boundary-author');
    $registrar->setPermissionsTeamId($denied->id);
    Role::findOrCreate('language-boundary-no-access', 'web');
    $actor->assignRoleForSite($denied, 'language-boundary-no-access');
    $first = Page::factory()->createOne(['site_id' => $allowed->id]);
    $second = Page::factory()->createOne(['site_id' => $denied->id]);
    $third = Page::factory()->createOne(['site_id' => $foreign->id]);
    $registrar->setPermissionsTeamId($allowed->id);
    test()->actingAs($actor);
    $matcher = new class implements DashboardPageLanguageMatcher
    {
        /** @var list<int> */
        public array $calls = [];

        #[Override]
        public function matches(Authenticatable $actor, Page $page, string $language): bool
        {
            $this->calls[] = $page->id;

            return $language === 'inherited';
        }
    };
    app()->instance(DashboardPageLanguageMatcher::class, $matcher);
    $action = resolve(CanViewDashboardItemAction::class);
    expect($action->handle($actor, $allowed->id, null, $first->id, 'inherited'))->toBeTrue()
        ->and($action->handle($actor, $allowed->id, null, $first->id, 'missing'))->toBeFalse()
        ->and($action->handle($actor, $denied->id, null, $second->id, 'inherited'))->toBeFalse()
        ->and($action->handle($actor, $foreign->id, null, $third->id, 'inherited'))->toBeFalse()
        ->and($action->handle($actor, $allowed->id, $denied->id, $first->id, 'inherited'))->toBeFalse()
        ->and($action->handle($actor, $allowed->id, null, $third->id, 'inherited'))->toBeFalse()
        ->and($matcher->calls)->toBe([$first->id, $first->id]);
});

it('keeps native language matching literal and excludes deleted pages', function (): void {
    $this->actingAsAdmin();
    $actor = $this->authenticatedUser();
    $page = Page::factory()->createOne();
    $language = Language::factory()->createOne(['code' => 'fr-native-dashboard']);
    Translation::withoutEvents(fn () => Translation::factory()->translatable($page)->createOne(['language_id' => $language->id]));
    $action = resolve(CanViewDashboardItemAction::class);
    expect($action->handle($actor, $page->site_id, null, $page->id, $language->code))->toBeTrue()
        ->and($action->handle($actor, $page->site_id, null, $page->id, 'not-a-page-language'))->toBeFalse();
    $page->delete();
    expect($action->handle($actor, $page->site_id, null, $page->id, $language->code))->toBeFalse();
});
