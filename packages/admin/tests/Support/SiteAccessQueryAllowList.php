<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Support;

use BezhanSalleh\FilamentShield\Support\Utils;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Core\Actions\Upgrade\ResolveInstalledComposerVersionsAction;
use Capell\Core\Enums\UrlTypeEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Language;
use Capell\Core\Models\Translation;
use Capell\Core\Support\Json\JsonCodec;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/** Exact file/method/origin exceptions; an unrelated query still fails closed. */
final class SiteAccessQueryAllowList
{
    /** @return array<string, list<string>> */
    public static function entries(): array
    {
        return [
            // Permission definitions, users and role pivots are installation-wide.
            'packages/admin/src/Filament/Actions/Site/ManageSitePermissionsAction.php' => [
                'assignmentsFor|' . DB::class . '::table($modelHasRolesTable)->where($teamColumn, $site->getKey())->whereIn(\'model_type\', $modelTypes)->orderBy(\'model_id\')->get([\'model_id\', \'role_id\'])',
                'assignmentsFor|$userModel::query()->whereKey($userIds)->pluck(\'id\')->map(fn(mixed $userId): int => (int) $userId)->all()', 'userOptions|$userModel::query()->orderBy(\'name\')->get([\'id\', \'name\', \'email\'])->mapWithKeys(fn(\\Illuminate\\Foundation\\Auth\\User $user): array => [(int) $user->getKey() => sprintf(\'%s <%s>\', $user->name, $user->email)])->all()',
            ],
            'packages/admin/src/Filament/Components/Forms/UserSelect.php' => [
                'setUp|$this->userModel()::query()->whereKey($value)->value(\'name\')', 'userQuery|$this->userModel()::query()->limit(10)',
            ],
            'packages/admin/src/Actions/AssignPermissionsToRole.php' => [
                'grantSuperAdminPermissions|$permissionModel::query()->where(\'guard_name\', ' . Utils::class . '::getFilamentAuthGuard())->whereIn(\'name\', $permissions)->pluck($this->modelKeyName($permissionModel))->all()',
                // Shield tenant enumeration assigns global permissions; it returns
                // no site content and is a system provisioning operation.
                'grantSuperAdminPermissions|$tenantModel::query()->pluck($this->modelKeyName($tenantModel))',
            ],
            'packages/admin/src/Actions/Diagnostics/CheckAdminPanelAccessAction.php' => [
                'handle|$userModel::query()->limit(250)->get()->all()', 'handle|$roleModel::query()->where(\'name\', $roleName)->where(\'guard_name\', $guard)->pluck($rolePrototype->getKeyName())',
                'handle|' . DB::class . '::table($pivotTable)->whereIn(\'role_id\', $roleIds)->where(\'model_type\', $userMorphType)->count()',
            ],
            'packages/admin/src/Actions/Sites/SyncSitePermissionsAction.php' => [
                'handle|' . DB::class . '::table($modelHasRolesTable)->where($teamColumn, $site->getKey())->delete()', 'handle|' . DB::class . '::table($modelHasRolesTable)->insertOrIgnore([\'role_id\' => $roleId, \'model_type\' => $user->getMorphClass(), \'model_id\' => $assignment->userId, $teamColumn => $site->getKey()])', 'usersById|$userModel::query()->whereKey($userIds)->get()->keyBy(fn(\\Illuminate\\Foundation\\Auth\\User $user): int => (int) $user->getKey())',
            ],
            'packages/admin/src/Support/HeaderNavigation/HeaderNavigationAccessResolver.php' => [
                'roleIdsForSite|' . DB::class . '::table($modelHasRolesTable)->where(\'model_type\', $actor->getMorphClass())->where(\'model_id\', $actor->getKey())->where(function (\Illuminate\Database\Query\Builder $query) use ($teamColumn, $siteId): void {
    $query->whereNull($teamColumn)->orWhere($teamColumn, $siteId);
})->pluck(\'role_id\')',
            ],
            // User preferences and operation notification recipients are global
            // user data, not CMS records owned by a local site.
            'packages/admin/src/Actions/Notifications/ResolveDefaultPackageOperationRecipientsAction.php' => ['handle|$model->newQuery()->orderBy($model->qualifyColumn($model->getKeyName()))'],
            'packages/admin/src/Actions/Users/ResolveAdminLocaleForUserAction.php' => ['handle|$user->newQuery()->whereKey($user->getKey())->value(\'preferred_admin_language_id\')'],
            'packages/admin/src/Actions/Users/ResolvePreferredAdminLanguageIdAction.php' => ['handle|$user->newQuery()->whereKey($user->getKey())->value(\'preferred_admin_language_id\')'],
            'packages/marketplace/src/Actions/ResolveMarketplaceInstallAttemptUserAction.php' => ['handle|$user->newQuery()->whereKey($attempt->user_id)->first()'],
            // Language definitions are global metadata, including a host override.
            'packages/admin/src/Filament/Components/Forms/Page/TitleWithSlugInput.php' => ['make|resolve(' . Language::class . '::class)::query()->find($languageId)'],
            'packages/admin/src/Support/Loader/LanguageLoader.php' => ['all|$model::query()->ordered()->get()', 'getDefault|$model::getDefault()', 'total|$model::query()->enabled()->count()'],
            // Global existence checks prevent deleting still-referenced definitions.
            // Their notification counts are separately scoped through SiteAccess.
            'packages/admin/src/Filament/Concerns/Validate/LanguageValidation.php' => ['validateDelete|$record->sites()->exists()'],
            'packages/admin/src/Filament/Concerns/Validate/LayoutValidation.php' => ['validateDelete|$record->pages()->exists()'],
            'packages/admin/src/Filament/Concerns/Validate/PageValidation.php' => ['validateDelete|$record->canonicalPages()->exists()'],
            'packages/admin/src/Filament/Concerns/Validate/ThemeValidation.php' => ['validateDelete|$record->sites()->exists()'],
            // An unused legacy theme is an installation-wide classification, with
            // no foreign usage count or foreign record exposed to the actor.
            'packages/admin/src/Actions/Themes/ResolveThemeLibraryAction.php' => ['isUnusedLegacyFoundationTheme|$theme->sites()->exists()'],
            // Advisory snapshots and dismissals describe installed software, not sites.
            'packages/admin/src/Filament/Pages/UpgradePage.php' => [
                'dismissNotice|' . DB::class . '::table(self::UPDATE_NOTICE_DISMISSALS_TABLE)->updateOrInsert([\'user_id\' => $userId, \'notice_id\' => $this->noticeId($notice)], [\'dismissed_until\' => null, \'updated_at\' => now(), \'created_at\' => now()])',
                'dismissedNoticeIds|' . DB::class . '::table(self::UPDATE_NOTICE_DISMISSALS_TABLE)->where(\'user_id\', $userId)->where(function (\Illuminate\Database\Query\Builder $query): void {
    $query->whereNull(\'dismissed_until\')->orWhere(\'dismissed_until\', \'>\', now());
})->pluck(\'notice_id\')->filter(fn(mixed $noticeId): bool => is_string($noticeId) && $noticeId !== \'\')->mapWithKeys(fn(string $noticeId): array => [$noticeId => true])->all()',
            ],
            'packages/admin/src/Filament/Widgets/Dashboard/UpdateAdvisoryFilamentWidget.php' => ['criticalSecurityAdvisories|' . DB::class . "::table(self::UPDATE_ADVISORY_SNAPSHOTS_TABLE)->latest('checked_at')->first()"],
            'packages/admin/src/Actions/CheckForUpdatesAction.php' => ['recordLocalUpdateCheck|' . DB::class . "::table(self::UPDATE_ADVISORY_SNAPSHOTS_TABLE)->insert(['source' => 'admin', 'checked_at' => now(), 'capell_version' => " . CapellCore::class . "::getInstalledPrettyVersion('capell-app/capell'), 'updates' => " . JsonCodec::class . "::encode([]), 'advisories' => " . JsonCodec::class . "::encode([]), 'metadata' => " . JsonCodec::class . "::encode(['installed_packages' => " . ResolveInstalledComposerVersionsAction::class . "::run()]), 'created_at' => now(), 'updated_at' => now()])"],
            'packages/admin/src/Actions/Upgrade/ReadLatestUpgradeSnapshotAction.php' => ['handle|' . DB::class . "::table(self::UPDATE_ADVISORY_SNAPSHOTS_TABLE)->latest('checked_at')->first()"],
            'packages/admin/src/Actions/Upgrade/RecordUpgradeSnapshotAction.php' => ['handle|' . DB::class . '::table(self::UPDATE_ADVISORY_SNAPSHOTS_TABLE)->insert([\'source\' => $source, \'checked_at\' => now(), \'capell_version\' => $capellVersion ?? ' . CapellCore::class . "::getInstalledPrettyVersion('capell-app/capell'), 'updates' => " . JsonCodec::class . '::encode($updates), \'advisories\' => ' . JsonCodec::class . '::encode($advisories), \'metadata\' => ' . JsonCodec::class . '::encode($metadata), \'created_at\' => now(), \'updated_at\' => now()])'],
            // pages() here is an extension registry or a helper returning an
            // already SiteAccess-scoped stream, rather than an Eloquent relation.
            'packages/admin/src/Filament/Plugin/CapellAdminPlugin.php' => [
                'register|' . CapellAdmin::class . '::getAdminSurfaceRegistry()->pages()',
                'registerPages|' . CapellAdmin::class . '::getAdminSurfaceRegistry()->pages()',
                'discoverInstalledPackageFilamentPages|' . CapellAdmin::class . '::getAdminSurfaceRegistry()->pages()',
                // Filament Panel registration accepts page class names, never records.
                'register|$panel->pages($pages)',
                'registerPages|$panel->pages(array_values(array_unique($pages)))',
            ],
            'packages/admin/src/Actions/SyncCapellPermissionsAction.php' => ['handle|' . CapellAdmin::class . '::getAdminSurfaceRegistry()->pages()'],
            'packages/admin/src/Actions/Reports/BuildPublishingReadinessReportAction.php' => ['handle|$this->pages()'],
            // These aggregate operations also serve setup/system callers. They
            // operate only on the supplied record (or newly created replica),
            // while Admin entry points authorise the record before calling them.
            'packages/admin/src/Actions/CreatePageAction.php' => [
                'handle|$pageModel::create(' . Arr::class . '::except($data, [\'translations\']))',
                'createTranslations|$page->translations()',
            ],
            'packages/admin/src/Filament/Resources/Sites/Pages/CreateSite.php' => ['createSiteDomainsFromState|$this->record->siteDomains()->exists()'],
            'packages/admin/src/Actions/ReplicatePageAction.php' => ['handle|$replica->translations()->create($translation)'],
            // Generic replication also serves system callers. This reload is
            // restricted to the supplied record's key; the Admin action's policy
            // authorises that record before invoking the aggregate operation.
            'packages/admin/src/Actions/ReplicateModelAction.php' => ['handle|$className::findOrFail($record->getKey())'],
            'packages/admin/src/Actions/SetupSiteLanguageAction.php' => ['handle|$site->translations()->first()', 'handle|$site->translations()->createOrFirst([\'language_id\' => $language->id], $translationValues)', 'handle|$site->siteDomains()->where(\'language_id\', $language->id)->exists()', 'handle|$site->siteDomains()->first()', 'handle|$site->siteDomains()->create([\'language_id\' => $language->id])'],
            'packages/admin/src/Actions/CheckTranslationCompletenessAction.php' => ['getDefaultTranslation|$translatable->translations()->whereRelation(\'language\', \'default\', true)->first()'],
            'packages/admin/src/Actions/Publishing/BuildPublishReadinessAction.php' => ['blockers|$record->translations()->exists()', 'blockers|$record->pageUrls()->enabled()->exists()', 'blockers|$record->layout()->exists()'],
            // Metadata synchronisation is bounded by the edited media morph/key;
            // the same helper supports non-interactive media setup.
            'packages/admin/src/Actions/Media/UpdateMediaAction.php' => ['syncLocalizedMetadata|' . Translation::class . '::query()->updateOrCreate([\'language_id\' => $languageId, \'translatable_type\' => $media->getMorphClass(), \'translatable_id\' => $media->getKey()], [\'title\' => $translationData[\'title\'] ?? null, \'meta\' => $meta])', 'syncLocalizedMetadata|$media->translations()->whereNotIn(\'language_id\', $seenLanguageIds)->delete()'],
            // Private staged media is deliberately deleted by its exact key
            // inside the replacement transaction, before it has a CMS owner.
            'packages/admin/src/Actions/ReplaceMediaFileAction.php' => ['handle|$staged->newQuery()->whereKey($staged->getKey())->forceDelete()'],
            // Redirect reconciliation is an aggregate operation; fresh descendant
            // bounds exclude unrelated IDs, and the Admin save authorises its root.
            'packages/admin/src/Actions/Pages/RecordDescendantUrlRedirectsAction.php' => ['handle|$request->page->newQuery()->whereDescendantOf($request->page->getKey())->get()->filter(fn(\\Illuminate\\Database\\Eloquent\\Model $descendant): bool => $descendant instanceof \\Capell\\Core\\Contracts\\Pageable)->keyBy(fn(\\Illuminate\\Database\\Eloquent\\Model $descendant): int => (int) $descendant->getKey())', 'urlChangedFor|$descendant->pageUrls()->where(\'language_id\', $languageId)->where(fn(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder => $query->whereNull(\'type\')->orWhere(\'type\', \'!=\', ' . UrlTypeEnum::class . "::Redirect))->value('url')"],
            // The invocation's actor/site and page have already been authorised;
            // this pivot read only acquires transaction locks, returning no content.
            'packages/admin/src/Support/Agent/AgentAdminToolInvocationService.php' => ['lockWriteTargets|' . DB::class . '::table(\'page_term\')->where(\'page_id\', $pageId)->lockForUpdate()->get()'],
        ];
    }
}
