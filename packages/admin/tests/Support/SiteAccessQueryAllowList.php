<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Support;

use BezhanSalleh\FilamentShield\Support\Utils;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Core\Actions\Upgrade\ResolveInstalledComposerVersionsAction;
use Capell\Core\Enums\ExtensionHealthAlertSeverity;
use Capell\Core\Enums\UrlTypeEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\ExtensionHealthAlert;
use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;
use Capell\Core\Support\Json\JsonCodec;
use Capell\Core\Support\Permissions\SiteAccess;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/** Exact file/method/origin exceptions; an unrelated query still fails closed. */
final class SiteAccessQueryAllowList
{
    /** @return array<string, list<string>> */
    public static function entries(): array
    {
        return [
            // Installation readiness is global; an actor with no site grants
            // must not be offered installation for an already installed CMS.
            'packages/admin/src/Filament/Pages/CapellDashboard.php' => ['getWidgets|' . Site::class . '::query()->exists()', 'dashboardEnum|' . Site::class . '::query()->exists()'],
            'packages/admin/src/Filament/Resources/Pages/Pages/CreatePage.php' => ['mount|' . Site::class . '::query()->exists()'],
            // The status panel authorises the record through its registered view
            // policy instead of SiteAccess: a shared (no-site) record that a
            // package policy allows for a site editor must stay reachable, and a
            // model with no policy is refused.
            'packages/admin/src/Filament/Livewire/PublishStatusPanel.php' => ['record|$class::query()->findOrFail($this->recordId)'],
            // Theme settings are installation-wide. Purging only the saving
            // actor's sites would retain stale public output on every other site.
            'packages/admin/src/Filament/Pages/SettingsPage.php' => ['allSiteSurrogateKeys|' . Site::class . '::query()->pluck(\'id\')->map(fn(int $siteId): string => \'site-\' . $siteId)->all()'],
            // Permission definitions, users and role pivots are installation-wide.
            'packages/admin/src/Filament/Actions/Site/ManageSitePermissionsAction.php' => ['assignmentsFor|' . DB::class . '::table($modelHasRolesTable)->where($teamColumn, $site->getKey())->whereIn(\'model_type\', $modelTypes)->orderBy(\'model_id\')->get([\'model_id\', \'role_id\'])', 'assignmentsFor|$userModel::query()->whereKey($userIds)->pluck(\'id\')->map(fn(mixed $userId): int => (int) $userId)->all()', 'userOptions|$userModel::query()->orderBy(\'name\')->get([\'id\', \'name\', \'email\'])->mapWithKeys(fn(\Illuminate\Foundation\Auth\User $user): array => [(int) $user->getKey() => sprintf(\'%s <%s>\', $user->name, $user->email)])->all()'],
            'packages/admin/src/Filament/Components/Forms/UserSelect.php' => ['setUp|$this->userModel()::query()->whereKey($value)->value(\'name\')', 'userQuery|$this->userModel()::query()->limit(10)'],
            'packages/admin/src/Actions/AssignPermissionsToRole.php' => [
                'grantSuperAdminPermissions|$permissionModel::query()->where(\'guard_name\', ' . Utils::class . '::getFilamentAuthGuard())->whereIn(\'name\', $permissions)->pluck($this->modelKeyName($permissionModel))->all()',
                // Shield tenant enumeration assigns global permissions; it returns
                // no site content and is a system provisioning operation.
                'grantSuperAdminPermissions|$tenantModel::query()->pluck($this->modelKeyName($tenantModel))',
            ],
            'packages/admin/src/Actions/Diagnostics/CheckAdminPanelAccessAction.php' => ['handle|$userModel::query()->limit(250)->get()->all()', 'handle|$roleModel::query()->where(\'name\', $roleName)->where(\'guard_name\', $guard)->pluck($rolePrototype->getKeyName())', 'handle|' . DB::class . '::table($pivotTable)->whereIn(\'role_id\', $roleIds)->where(\'model_type\', $userMorphType)->count()'],
            'packages/admin/src/Actions/Sites/SyncSitePermissionsAction.php' => ['handle|' . DB::class . '::table($modelHasRolesTable)->where($teamColumn, $site->getKey())->delete()', 'handle|' . DB::class . '::table($modelHasRolesTable)->insertOrIgnore([\'role_id\' => $roleId, \'model_type\' => $user->getMorphClass(), \'model_id\' => $assignment->userId, $teamColumn => $site->getKey()])', 'usersById|$userModel::query()->whereKey($userIds)->get()->keyBy(fn(\Illuminate\Foundation\Auth\User $user): int => (int) $user->getKey())'],
            'packages/admin/src/Support/HeaderNavigation/HeaderNavigationAccessResolver.php' => ['roleIdsForSite|' . DB::class . '::table($modelHasRolesTable)->where(\'model_type\', $actor->getMorphClass())->where(\'model_id\', $actor->getKey())->where(function (\Illuminate\Database\Query\Builder $query) use ($teamColumn, $siteId): void {
    $query->whereNull($teamColumn)->orWhere($teamColumn, $siteId);
})->pluck(\'role_id\')'],
            // User preferences and operation notification recipients are global
            // user data, not CMS records owned by a local site.
            'packages/admin/src/Actions/Notifications/ResolveDefaultPackageOperationRecipientsAction.php' => ['handle|$model->newQuery()->orderBy($model->qualifyColumn($model->getKeyName()))'],
            'packages/admin/src/Actions/Users/ResolveAdminLocaleForUserAction.php' => ['handle|$user->newQuery()->whereKey($user->getKey())->value(\'preferred_admin_language_id\')'],
            'packages/admin/src/Actions/Users/ResolvePreferredAdminLanguageIdAction.php' => ['handle|$user->newQuery()->whereKey($user->getKey())->value(\'preferred_admin_language_id\')'],
            'packages/marketplace/src/Actions/ResolveMarketplaceInstallAttemptUserAction.php' => ['handle|$user->newQuery()->whereKey($attempt->user_id)->first()'],
            // Language definitions are global metadata, including a host override.
            'packages/admin/src/Filament/Components/Forms/Page/TitleWithSlugInput.php' => ['make|resolve(' . Language::class . '::class)::query()->find($languageId)'],
            'packages/admin/src/Support/Loader/LanguageLoader.php' => [
                'all|$model::query()->ordered()->get()',
                'getDefault|$model::getDefault()',
                'total|$model::query()->enabled()->count()',
                // The query returns global Language definitions only; no site record, content or usage count is exposed.
                'languages|' . Language::class . '::query()->whereRelation(\'sites\', \'sites.id\', $siteId)->enabled()->ordered()->get()',
            ],
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
            'packages/admin/src/Filament/Pages/UpgradePage.php' => ['dismissNotice|' . DB::class . '::table(self::UPDATE_NOTICE_DISMISSALS_TABLE)->updateOrInsert([\'user_id\' => $userId, \'notice_id\' => $this->noticeId($notice)], [\'dismissed_until\' => null, \'updated_at\' => now(), \'created_at\' => now()])', 'dismissedNoticeIds|' . DB::class . '::table(self::UPDATE_NOTICE_DISMISSALS_TABLE)->where(\'user_id\', $userId)->where(function (\Illuminate\Database\Query\Builder $query): void {
    $query->whereNull(\'dismissed_until\')->orWhere(\'dismissed_until\', \'>\', now());
})->pluck(\'notice_id\')->filter(fn(mixed $noticeId): bool => is_string($noticeId) && $noticeId !== \'\')->mapWithKeys(fn(string $noticeId): array => [$noticeId => true])->all()'],
            'packages/admin/src/Filament/Widgets/Dashboard/UpdateAdvisoryFilamentWidget.php' => ['criticalSecurityAdvisories|' . DB::class . "::table(self::UPDATE_ADVISORY_SNAPSHOTS_TABLE)->latest('checked_at')->first()"],
            'packages/admin/src/Actions/CheckForUpdatesAction.php' => ['recordLocalUpdateCheck|' . DB::class . "::table(self::UPDATE_ADVISORY_SNAPSHOTS_TABLE)->insert(['source' => 'admin', 'checked_at' => now(), 'capell_version' => " . CapellCore::class . "::getInstalledPrettyVersion('capell-app/capell'), 'updates' => " . JsonCodec::class . "::encode([]), 'advisories' => " . JsonCodec::class . "::encode([]), 'metadata' => " . JsonCodec::class . "::encode(['installed_packages' => " . ResolveInstalledComposerVersionsAction::class . "::run()]), 'created_at' => now(), 'updated_at' => now()])"],
            'packages/admin/src/Actions/Upgrade/ReadLatestUpgradeSnapshotAction.php' => ['handle|' . DB::class . "::table(self::UPDATE_ADVISORY_SNAPSHOTS_TABLE)->latest('checked_at')->first()"],
            'packages/admin/src/Actions/Upgrade/RecordUpgradeSnapshotAction.php' => ['handle|' . DB::class . '::table(self::UPDATE_ADVISORY_SNAPSHOTS_TABLE)->insert([\'source\' => $source, \'checked_at\' => now(), \'capell_version\' => $capellVersion ?? ' . CapellCore::class . "::getInstalledPrettyVersion('capell-app/capell'), 'updates' => " . JsonCodec::class . '::encode($updates), \'advisories\' => ' . JsonCodec::class . '::encode($advisories), \'metadata\' => ' . JsonCodec::class . '::encode($metadata), \'created_at\' => now(), \'updated_at\' => now()])'],
            // pages() here is an extension registry or a helper returning an
            // already SiteAccess-scoped stream, rather than an Eloquent relation.
            'packages/admin/src/Filament/Plugin/CapellAdminPlugin.php' => ['register|' . CapellAdmin::class . '::getAdminSurfaceRegistry()->pages()', 'registerPages|' . CapellAdmin::class . '::getAdminSurfaceRegistry()->pages()', 'discoverInstalledPackageFilamentPages|' . CapellAdmin::class . '::getAdminSurfaceRegistry()->pages()'],
            'packages/admin/src/Actions/SyncCapellPermissionsAction.php' => ['handle|' . CapellAdmin::class . '::getAdminSurfaceRegistry()->pages()'],
            // These aggregate operations also serve setup/system callers. They
            // operate only on the supplied record (or newly created replica),
            // while Admin entry points authorise the record before calling them.
            'packages/admin/src/Actions/CreatePageAction.php' => ['handle|$pageModel::create(' . Arr::class . '::except($data, [\'translations\']))'],
            'packages/admin/src/Filament/Resources/Sites/Pages/CreateSite.php' => ['createSiteDomainsFromState|$this->record->siteDomains()->exists()'],
            // Generic replication also serves system callers. This reload is
            // restricted to the supplied record's key; the Admin action's policy
            // authorises that record before invoking the aggregate operation.
            'packages/admin/src/Actions/ReplicateModelAction.php' => ['handle|$className::findOrFail($record->getKey())'],
            'packages/admin/src/Actions/CheckTranslationCompletenessAction.php' => ['getDefaultTranslation|$translatable->translations()->whereRelation(\'language\', \'default\', true)->first()'],
            'packages/admin/src/Actions/Publishing/BuildPublishReadinessAction.php' => ['blockers|$record->translations()->exists()', 'blockers|$record->pageUrls()->enabled()->exists()', 'blockers|$record->layout()->exists()'],
            // Metadata synchronisation is bounded by the edited media morph/key;
            // the same helper supports non-interactive media setup.
            'packages/admin/src/Actions/Media/UpdateMediaAction.php' => ['syncLocalizedMetadata|' . Translation::class . '::query()->updateOrCreate([\'language_id\' => $languageId, \'translatable_type\' => $media->getMorphClass(), \'translatable_id\' => $media->getKey()], [\'title\' => $translationData[\'title\'] ?? null, \'meta\' => $meta])'],
            // Private staged media is deliberately deleted by its exact key
            // inside the replacement transaction, before it has a CMS owner.
            'packages/admin/src/Actions/ReplaceMediaFileAction.php' => [
                'handle|$staged->newQuery()->whereKey($staged->getKey())->forceDelete()',
                // The replacement action locks only the supplied, authorised media key; no other media row can be returned.
                'handle|$media->newQuery()->whereKey($media->getKey())->lockForUpdate()->firstOrFail()',
            ],
            // Redirect reconciliation is an aggregate operation; fresh descendant
            // bounds exclude unrelated IDs, and the Admin save authorises its root.
            'packages/admin/src/Actions/Pages/RecordDescendantUrlRedirectsAction.php' => ['handle|$request->page->newQuery()->whereDescendantOf($request->page->getKey())->get()->filter(fn(\Illuminate\Database\Eloquent\Model $descendant): bool => $descendant instanceof \Capell\Core\Contracts\Pageable)->keyBy(fn(\Illuminate\Database\Eloquent\Model $descendant): int => (int) $descendant->getKey())', 'urlChangedFor|$descendant->pageUrls()->where(\'language_id\', $languageId)->where(fn(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder => $query->whereNull(\'type\')->orWhere(\'type\', \'!=\', ' . UrlTypeEnum::class . "::Redirect))->value('url')"],
            // The invocation's actor/site and page have already been authorised;
            // this pivot read only acquires transaction locks, returning no content.
            'packages/admin/src/Support/Agent/AgentAdminToolInvocationService.php' => ['lockWriteTargets|' . DB::class . '::table(\'page_term\')->where(\'page_id\', $pageId)->lockForUpdate()->get()'],
            'packages/admin/src/Filament/Resources/Pages/Pages/EditPage.php' => [
                // Filament resolves and authorises this page; its translations belong only to that page morph/key.
                'pageTypeContentStructureUpdated|$this->record->translations',
                // This is the parent-page relation of the page already resolved through PageResource and authorised by Filament.
                'resolveRecord|$query->with([\'blueprint\', \'translations.language\'])',
            ],
            'packages/admin/src/Filament/Resources/Pages/Tables/PagesTable.php' => [
                // The caller supplies the scoped PageResource query; translation predicates stay correlated to those pages.
                'applyNameSearch|$query->where(\'name\', \'like\', sprintf(\'%%%s%%\', $search))->orWhereHas(\'translations\', fn(Illuminate\Contracts\Database\Eloquent\Builder $query): Illuminate\Contracts\Database\Eloquent\Builder => $query->where(\'title\', \'like\', sprintf(\'%%%s%%\', $search)))',
                // Only global language names/codes are returned; the site predicate selects definitions without returning site data.
                'getLanguageSearchResults|$query->whereHas(\'sites\', fn(Illuminate\Contracts\Database\Eloquent\Builder $query): Illuminate\Contracts\Database\Eloquent\Builder => $query->where(\'sites.id\', $activeTabSiteId))',
                // PageResource supplies the SiteAccess-scoped page query; the translation filter stays correlated to each visible page.
                'applyFilterQuery|$query->whereHas(\'translations\', fn(Illuminate\Contracts\Database\Eloquent\Builder $query): Illuminate\Contracts\Database\Eloquent\Builder => $query->where(\'language_id\', (int) $languageId))',
                // PageResource supplies the SiteAccess-scoped page query; the translation filter stays correlated to each visible page.
                'applyMissingTranslationFilterQuery|$query->whereDoesntHave(\'translations\', fn(Illuminate\Contracts\Database\Eloquent\Builder $query): Illuminate\Contracts\Database\Eloquent\Builder => $query->where(\'language_id\', (int) $languageId)->where(fn(Illuminate\Contracts\Database\Eloquent\Builder $query): Illuminate\Contracts\Database\Eloquent\Builder => $query->where(\'title\', \'!=\', \'\')->orWhere(\'content\', \'!=\', \'\')))',
            ],
            'packages/admin/src/Filament/Resources/Sites/Tables/SitesTable.php' => [
                // SiteResource supplies the SiteAccess-scoped query; these counts and eager loads belong to each visible site.
                'configure|$query->with([\'creator\', \'editor\', \'language\', \'translations.language\', \'siteDomains.language\', \'blueprint\', \'theme.blueprint\'])->withCount([\'pages\', \'siteDomains\'])->withoutGlobalScopes([' . SoftDeletingScope::class . '::class])',
                // The filter modifies the scoped SiteResource query; its translation/domain predicates stay correlated to that site.
                'getTableFilters|$query->where(\'language_id\', $data[\'language_id\'])->orWhereHas(\'translations\', fn(Illuminate\Contracts\Database\Eloquent\Builder $query): Illuminate\Contracts\Database\Eloquent\Builder => $query->where(\'language_id\', $data[\'language_id\']))->orWhereHas(\'siteDomains\', fn(Illuminate\Contracts\Database\Eloquent\Builder $query): Illuminate\Contracts\Database\Eloquent\Builder => $query->where(\'language_id\', $data[\'language_id\']))',
            ],
            'packages/admin/src/Actions/Extensions/BuildExtensionOperationsSummaryAction.php' => [
                // Null/global and assigned affected_site_id values are filtered before alerts are read; actor changes invalidate the request cache.
                'healthAlerts|' . ExtensionHealthAlert::class . '::query()->when($siteIds !== null, fn(Illuminate\Database\Eloquent\Builder $query): Illuminate\Database\Eloquent\Builder => $query->where(fn(Illuminate\Database\Eloquent\Builder $query): Illuminate\Database\Eloquent\Builder => $query->whereNull(\'affected_site_id\')->orWhereIn(\'affected_site_id\', $siteIds)))->where(fn(Illuminate\Database\Eloquent\Builder $query) => $query->whereNull(\'expires_at\')->orWhere(\'expires_at\', \'>\', now()))->get()->groupBy(\'composer_name\')',
            ],
            'packages/admin/src/Actions/HeaderNavigation/SearchHeaderNavigationPagesAction.php' => [
                // The caller starts at SiteAccess::forActor; this nested search predicate stays correlated to its visible pages.
                'applySearchConstraint|$query->where(\'pages.name\', \'like\', $like)->orWhereHas(\'translations\', fn(Illuminate\Contracts\Database\Eloquent\Builder $query): Illuminate\Contracts\Database\Eloquent\Builder => $query->where(\'title\', \'like\', $like)->orWhere(\'meta->slug\', \'like\', $like))->orWhereHas(\'pageUrls\', fn(Illuminate\Contracts\Database\Eloquent\Builder $query): Illuminate\Contracts\Database\Eloquent\Builder => $query->where(\'url\', \'like\', $like))',
            ],
            'packages/admin/src/Actions/Themes/CreateAvailableThemeAction.php' => [
                // The receiver is a validated ThemeDefinitionData from the definition catalogue; assets are path strings, not a relation.
                'createTheme|$definition->assets',
            ],
            'packages/marketplace/src/Filament/Widgets/ExtensionHealthAlertsFilamentWidget.php' => [
                // Null/global and assigned affected_site_id values are filtered before the critical-alert limit and read.
                'queryCriticalAlerts|' . ExtensionHealthAlert::class . '::query()->when($siteIds !== null, function (Illuminate\Database\Eloquent\Builder $query) use ($siteIds): void {
    $query->where(fn(Illuminate\Database\Eloquent\Builder $query): Illuminate\Database\Eloquent\Builder => $query->whereNull(\'affected_site_id\')->orWhereIn(\'affected_site_id\', $siteIds));
})->where(\'severity\', ' . ExtensionHealthAlertSeverity::class . '::Critical->value)->where(function (Illuminate\Database\Eloquent\Builder $query): void {
    $query->whereNull(\'expires_at\')->orWhere(\'expires_at\', \'>\', now());
})->where(function (Illuminate\Database\Eloquent\Builder $query) use ($extensionSlug, $composerName): void {
    $query->where(\'extension_slug\', $extensionSlug);
    if (is_string($composerName) && $composerName !== \'\') {
        $query->orWhere(\'composer_name\', $composerName);
    }
})->latest(\'issued_at\')->latest()',
            ],
            'packages/admin/src/Data/Pages/PageAvailabilityData.php' => [
                // The supplied page is authorised by its Admin caller; pageUrls is bounded to that exact page morph/key.
                'fromPage|$page->pageUrls()->get()',
            ],
            'packages/admin/src/Filament/Imports/RedirectImporter.php' => [
                // This is a LanguageSelect query returning global language definitions only; its site predicate exposes no site content or count.
                'getOptionsFormComponents|$query->whereHas(\'sites\', fn(Illuminate\Contracts\Database\Eloquent\Builder $query): Illuminate\Contracts\Database\Eloquent\Builder => $query->where(\'sites.id\', $siteId))',
            ],
            'packages/admin/src/Filament/Resources/Media/MediaResource.php' => [
                // scopeMedia authorises each media owner before this read; translations are bounded to that exact media morph/key.
                'getEloquentQuery|' . SiteAccess::class . "::current()->scopeMedia(parent::getEloquentQuery())->with(['model', 'translations.language'])",
            ],
            'packages/admin/src/Filament/Resources/PageUrls/Schemas/PageUrlForm.php' => [
                // PageMorphToSelect supplies the SiteAccess-scoped page query; translations stay correlated to each visible page.
                'getFormSchema|$query->whereHas(\'sites\', fn(Illuminate\Contracts\Database\Eloquent\Builder $query): Illuminate\Contracts\Database\Eloquent\Builder => $query->where(\'sites.id\', $siteId))',
                // PageMorphToSelect supplies the SiteAccess-scoped page query; translations stay correlated to each visible page.
                'getFormSchema|$query->whereHas(\'translations\', fn(Illuminate\Contracts\Database\Eloquent\Builder $query): Illuminate\Contracts\Database\Eloquent\Builder => $query->where(\'language_id\', $get(\'language_id\')))',
            ],
            'packages/admin/src/Filament/Resources/Redirects/Schemas/RedirectForm.php' => [
                // This is a LanguageSelect query returning global language definitions only; its site predicate exposes no site content or count.
                'getFormSchema|$query->whereHas(\'sites\', fn(Illuminate\Contracts\Database\Eloquent\Builder $query): Illuminate\Contracts\Database\Eloquent\Builder => $query->where(\'sites.id\', $siteId))',
            ],
        ];
    }
}
