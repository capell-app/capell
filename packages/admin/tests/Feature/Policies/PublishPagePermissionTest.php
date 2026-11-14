<?php

declare(strict_types=1);

use Capell\Admin\Actions\EnsureCapellPermissionsAction;
use Capell\Admin\Enums\CapellPermission;
use Capell\Admin\Enums\ResourceEnum;
use Capell\Core\Actions\Publishing\Review\ApprovePageReviewAction;
use Capell\Core\Actions\Publishing\Review\SubmitPageForReviewAction;
use Capell\Core\Contracts\Publishing\AuthorizesPublicationTransition;
use Capell\Core\Data\Publishing\PublicationTransitionRequestData;
use Capell\Core\Enums\BlueprintGroupEnum;
use Capell\Core\Enums\Publishing\PublicationTransition;
use Capell\Core\EventSourcing\Enums\PageWorkflowStatus;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

function contributorRole(): Role
{
    EnsureCapellPermissionsAction::run();
    $role = Role::query()->firstOrCreate(['name' => 'contributor', 'guard_name' => 'web']);

    foreach (['view_any', 'view', 'update'] as $affix) {
        $role->givePermissionTo(Permission::findOrCreate(ResourceEnum::Page->permission($affix), 'web'));
    }

    return $role;
}

function actingAsContributorOn(Site $site): void
{
    resolve(PermissionRegistrar::class)->forgetCachedPermissions();
    $user = test()->createUserWithRole('contributor');
    $user->assignedSiteIds = collect([$site->getKey()]);

    test()->actingAs($user);
}

function contributorPage(Site $site): Page
{
    $blueprint = Blueprint::factory()->page()->group(BlueprintGroupEnum::Default->value)->createOne();

    return Page::factory()->site($site)->type($blueprint)->withTranslations()->createOne();
}

function publishAllowed(Page $page, PublicationTransition $transition): bool
{
    return resolve(AuthorizesPublicationTransition::class)->allows(new PublicationTransitionRequestData(
        $page,
        $transition,
        test()->authenticatedUser(),
        CarbonImmutable::now(),
    ));
}

it('lets a role that can update pages edit and submit but not publish without the publish permission', function (): void {
    contributorRole()->revokePermissionTo(CapellPermission::PublishPage->name());
    $site = Site::factory()->hasSiteDomains()->createOne();
    actingAsContributorOn($site);
    $page = contributorPage($site);

    expect(publishAllowed($page, PublicationTransition::PublishNow))->toBeFalse()
        ->and(publishAllowed($page, PublicationTransition::Unpublish))->toBeFalse()
        ->and(publishAllowed($page, PublicationTransition::RevertToDraft))->toBeTrue();

    SubmitPageForReviewAction::run($page);

    expect(fn (): mixed => ApprovePageReviewAction::run($page))->toThrow(AuthorizationException::class);
});

it('lets a reviewer with the publish permission approve a submitted page', function (): void {
    contributorRole()->givePermissionTo(CapellPermission::PublishPage->name());
    $site = Site::factory()->hasSiteDomains()->createOne();
    actingAsContributorOn($site);
    $page = contributorPage($site);

    SubmitPageForReviewAction::run($page);
    ApprovePageReviewAction::run($page);

    expect(publishAllowed($page, PublicationTransition::PublishNow))->toBeTrue()
        ->and(DB::table('page_workflow_states')->where('page_uuid', $page->uuid)->value('status'))->toBe(PageWorkflowStatus::Approved->value);
});

it('grants the publish permission once to every role that could update pages', function (): void {
    $role = contributorRole();
    Permission::query()->where('name', CapellPermission::PublishPage->name())->delete();
    resolve(PermissionRegistrar::class)->forgetCachedPermissions();

    $migration = require dirname(__DIR__, 3) . '/database/migrations/2026_11_14_000001_grant_publish_page_where_update_page_is_granted.php';
    $migration->up();

    expect(Role::query()->whereKey($role->getKey())->sole()->hasPermissionTo(CapellPermission::PublishPage->name(), 'web'))->toBeTrue();
});
