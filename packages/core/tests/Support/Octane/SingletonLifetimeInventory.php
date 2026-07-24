<?php

declare(strict_types=1);

namespace Capell\Tests\Support\Octane;

use Capell\Admin\Support\Activity\ActivityResourceLinkRegistry;
use Capell\Admin\Support\AdminEventRegistry;
use Capell\Admin\Support\AdminEventRouter;
use Capell\Admin\Support\AdminSurfaceContributionCache;
use Capell\Admin\Support\AdminSurfaceContributionRegistry;
use Capell\Admin\Support\Bridges\AdminBridgeRegistrar;
use Capell\Admin\Support\Bridges\AdminBridgeRegistry;
use Capell\Admin\Support\CapellAdminManager;
use Capell\Admin\Support\Dashboard\DashboardFilamentWidgetRegistry;
use Capell\Admin\Support\Dashboard\OverviewStatRegistry;
use Capell\Admin\Support\Extensions\ExtensionManagementSurfaceRegistry;
use Capell\Admin\Support\Extensions\ExtensionPageRegistry;
use Capell\Admin\Support\Extensions\ExtensionsPageActionRegistry;
use Capell\Admin\Support\ImportEntryRegistry;
use Capell\Admin\Support\Install\AdminPermissionSynchronizer;
use Capell\Admin\Support\MarketingStudio\MarketingStudioActionRegistry;
use Capell\Admin\Support\Notifications\AdminNotificationGroupRegistry;
use Capell\Admin\Support\Reports\ReportRegistry;
use Capell\Admin\Support\UserMenu\UserMenuItemRegistry;
use Capell\Admin\Support\Widgets\WidgetDiscovery;
use Capell\Core\Actions\DemoPackageAction;
use Capell\Core\Actions\Install\InstallDeveloperToolingAction;
use Capell\Core\Actions\RequirePackageAction;
use Capell\Core\Actions\SanitizeSiteSpecSectionHtmlAction;
use Capell\Core\Concerns\HasModelRelations;
use Capell\Core\EventSourcing\Rollback\RollbackValidatorRegistry;
use Capell\Core\EventSourcing\Support\EventSourcedRegistry;
use Capell\Core\Models\Concerns\ExtensibleModel;
use Capell\Core\Support\Assets\VendorAssetConditionRegistry;
use Capell\Core\Support\Backup\DatabaseBackupDriverRegistry;
use Capell\Core\Support\Cache\CapellCacheManager;
use Capell\Core\Support\CapellCoreManager;
use Capell\Core\Support\Components\ComponentRegistry;
use Capell\Core\Support\ContentGraph\ContentGraphRegistry;
use Capell\Core\Support\Install\InstallPatchRegistry;
use Capell\Core\Support\Links\LinkableContentRegistry;
use Capell\Core\Support\Makers\MakerRegistry;
use Capell\Core\Support\Manifest\ManifestLoader;
use Capell\Core\Support\Metrics\MetricCollectorRegistry;
use Capell\Core\Support\Metrics\MetricEventRegistry;
use Capell\Core\Support\Metrics\MetricsManager;
use Capell\Core\Support\Models\ModelInterceptorRegistry;
use Capell\Core\Support\PackageRegistry\CapellPackageRegistry;
use Capell\Core\Support\Packages\PackageSurfaceRegistrar;
use Capell\Core\Support\Presentation\PresentationPresetRegistry;
use Capell\Core\Support\Renderables\RenderableRegistry;
use Capell\Core\Support\Security\LockdownStore;
use Capell\Core\Support\Settings\SettingsSchemaRegistry;
use Capell\Core\Support\SiteAccess\SiteAccessPolicyRegistry;
use Capell\Core\Support\Subscriber\SubscriberRegistry;
use Capell\Core\Support\Themes\ThemeChromeRegistry;
use Capell\Core\Support\Themes\ThemeInstallDefaultsRegistry;
use Capell\Core\ThemeStudio\Assets\ThemeTokenStore;
use Capell\Core\ThemeStudio\Discovery\LocalAppThemeDefinitionRepository;
use Capell\Core\ThemeStudio\Preview\ThemePreviewContext;
use Capell\Core\ThemeStudio\Theme\PagePresentationRegistry;
use Capell\Core\ThemeStudio\Theme\ThemeRegistry;
use Capell\Core\ThemeStudio\Theme\WidgetPresentationRegistry;
use Capell\Frontend\Contracts\FrontendContextReader;
use Capell\Frontend\Support\Assets\DefaultFrontendResourcePlanRenderer;
use Capell\Frontend\Support\Assets\FrontendAssetsService;
use Capell\Frontend\Support\Assets\FrontendPackageDependencyRegistry;
use Capell\Frontend\Support\Assets\FrontendResourceRegistry;
use Capell\Frontend\Support\Assets\FrontendViteInputRegistry;
use Capell\Frontend\Support\Cache\CacheInvalidationDependencyRegistry;
use Capell\Frontend\Support\Components\FrontendComponentRegistry;
use Capell\Frontend\Support\Links\PublicRouteAliasRegistry;
use Capell\Frontend\Support\Render\FrontendHookRegistrar;
use Capell\Frontend\Support\Render\RenderHookRegistry;
use Capell\Frontend\Support\Renderables\RenderableDynamicDataRegistry;
use Capell\Frontend\Support\Routing\FrontendRouteMiddlewareRegistry;
use Capell\Frontend\Support\Routing\ReservedFrontendDomainRegistry;
use Capell\Frontend\Support\Routing\ReservedFrontendPathRegistry;
use Capell\Frontend\Support\Routing\ReservedFrontendRequest;
use Capell\Frontend\Support\Rules\FrontendRuleConditionRegistry;
use Capell\Frontend\Support\State\FrontendState;
use Capell\Frontend\Support\View\ThemeChainResolver;
use Capell\Frontend\Support\View\ThemeViewRegistrar;
use Capell\Installer\Support\InstallGuide\PatchRegistry;
use InvalidArgumentException;

/**
 * Executable inventory of mutable Capell singleton state.
 *
 * Extension authors must keep registration-only state boot-immutable. State
 * derived from an operation belongs in a scoped binding, or in a Resettable
 * singleton tagged with Resettable::TAG. A singleton must never do both.
 */
final class SingletonLifetimeInventory
{
    /** @return array<string, string> */
    public static function dynamicBindingTargets(): array
    {
        return [
            ThemePreviewContext::class => ThemePreviewContext::class,
            FrontendContextReader::class => FrontendState::class,
        ];
    }

    /**
     * @return array<class-string, array{lifetime: SingletonLifetime, protection: 'boot'|'tagged'|'delegated', reason: non-empty-string}>
     */
    public static function mutableSingletons(): array
    {
        return [
            // Core boot registration state.
            CapellPackageRegistry::class => self::boot('Package manifests are discovered once and invalidated only by explicit package mutation.'),
            ModelInterceptorRegistry::class => self::boot('Model interceptors are package boot registrations.'),
            SubscriberRegistry::class => self::boot('Subscribers are package boot registrations.'),
            RenderableRegistry::class => self::boot('Renderable types are package boot registrations.'),
            LinkableContentRegistry::class => self::boot('Linkable content types are package boot registrations.'),
            ContentGraphRegistry::class => self::boot('Content graph nodes and edges are package boot registrations.'),
            ThemeChromeRegistry::class => self::boot('Theme chrome definitions are package boot registrations.'),
            ThemeInstallDefaultsRegistry::class => self::boot('Theme install defaults are package boot registrations.'),
            InstallPatchRegistry::class => self::boot('Install patches are package boot registrations.'),
            PresentationPresetRegistry::class => self::boot('Presentation presets are package boot registrations.'),
            VendorAssetConditionRegistry::class => self::boot('Vendor asset conditions are package boot registrations.'),
            ThemeRegistry::class => self::boot('Themes are package boot registrations.'),
            PagePresentationRegistry::class => self::boot('Page presentation definitions are package boot registrations.'),
            WidgetPresentationRegistry::class => self::boot('Widget presentation definitions are package boot registrations.'),
            ThemeTokenStore::class => self::boot('Theme tokens are package boot registrations.'),
            CapellCoreManager::class => self::tagged('Boot registrations persist while the manager selectively flushes only operation-derived default-page state.'),
            ComponentRegistry::class => self::tagged('Boot component registrations and namespaces persist while discovered components and the cache-presence memo are selectively flushed.'),
            CapellCacheManager::class => self::delegated('The core manager flushes this per-operation in-memory cache.'),
            LockdownStore::class => self::tagged('Lockdown decisions are operation-derived and explicitly flushed.'),
            RollbackValidatorRegistry::class => self::boot('Rollback validators are package boot registrations.'),
            SettingsSchemaRegistry::class => self::boot('Settings schemas and metadata are package boot registrations.'),
            SiteAccessPolicyRegistry::class => self::boot('Site access policy providers are package boot registrations.'),
            MetricEventRegistry::class => self::boot('Metric event definitions are package boot registrations.'),
            MetricCollectorRegistry::class => self::boot('Metric collectors are package boot registrations.'),
            MetricsManager::class => self::stateless('The manager delegates to the boot metric registry and event storage action.'),

            // Admin boot registration state.
            ExtensionPageRegistry::class => self::boot('Extension pages are package boot registrations.'),
            AdminNotificationGroupRegistry::class => self::boot('Notification groups are package boot registrations.'),
            ActivityResourceLinkRegistry::class => self::boot('Activity resource links are package boot registrations.'),
            AdminSurfaceContributionRegistry::class => self::boot('Admin surfaces are package boot registrations.'),
            AdminSurfaceContributionCache::class => self::boot('Admin surface contributions are derived once from boot registrations and invalidated only by explicit registration changes.'),
            ReportRegistry::class => self::boot('Reports are package boot registrations.'),
            DashboardFilamentWidgetRegistry::class => self::boot('Dashboard widgets are package boot registrations.'),
            MarketingStudioActionRegistry::class => self::boot('Marketing actions are package boot registrations.'),
            UserMenuItemRegistry::class => self::boot('User menu definitions are package boot registrations; resolution is scoped.'),
            OverviewStatRegistry::class => self::boot('Overview stats are package boot registrations.'),
            AdminBridgeRegistry::class => self::boot('Admin bridge contributions are package boot registrations.'),
            ImportEntryRegistry::class => self::boot('Import entries are package boot registrations.'),
            ExtensionManagementSurfaceRegistry::class => self::boot('Extension management surfaces are package boot registrations.'),
            ExtensionsPageActionRegistry::class => self::boot('Extension page actions are package boot registrations.'),
            AdminEventRegistry::class => self::boot('Admin events are package boot registrations.'),
            CapellAdminManager::class => self::boot('The manager delegates to boot registries and does not retain operation payloads.'),
            WidgetDiscovery::class => self::boot('Widget sources and authoritative widget definitions are discovered from package boot registrations.'),
            AdminEventRouter::class => self::boot('The router reads the boot-lifetime admin event registry and does not retain operation payloads.'),
            AdminBridgeRegistrar::class => self::boot('The registrar delegates only to boot-lifetime bridge and settings registries.'),
            AdminPermissionSynchronizer::class => self::stateless('The synchronizer retains collaborators but no operation-derived values.'),

            // Frontend boot registration state and one reset participant.
            FrontendAssetsService::class => self::boot('Asset declarations are package boot registrations.'),
            FrontendComponentRegistry::class => self::boot('Frontend components are package boot registrations.'),
            PublicRouteAliasRegistry::class => self::boot('Public route aliases are package boot registrations.'),
            RenderableDynamicDataRegistry::class => self::boot('Dynamic data resolvers are package boot registrations.'),
            RenderHookRegistry::class => self::boot('Render hooks are package boot registrations.'),
            FrontendRuleConditionRegistry::class => self::boot('Rule conditions are package boot registrations.'),
            ReservedFrontendPathRegistry::class => self::boot('Reserved paths are package boot registrations.'),
            ReservedFrontendDomainRegistry::class => self::boot('Reserved domains are package boot registrations.'),
            ThemeViewRegistrar::class => self::tagged('View finder namespace hints are restored after every operation.'),
            FrontendRouteMiddlewareRegistry::class => self::boot('Route middleware declarations are package boot registrations.'),
            FrontendResourceRegistry::class => self::boot('Frontend resources are package boot registrations.'),
            FrontendPackageDependencyRegistry::class => self::boot('Frontend package dependencies are package boot registrations.'),
            FrontendViteInputRegistry::class => self::boot('Vite inputs are package boot registrations.'),
            CacheInvalidationDependencyRegistry::class => self::boot('Invalidation dependencies are package boot registrations.'),
            DefaultFrontendResourcePlanRenderer::class => self::stateless('The renderer retains collaborators but no operation-derived values.'),
            FrontendHookRegistrar::class => self::boot('The registrar delegates only to the boot-lifetime render hook registry.'),
            ReservedFrontendRequest::class => self::boot('The predicate reads boot-lifetime reserved path and domain registries.'),
            ThemeChainResolver::class => self::boot('Theme-chain inputs are package and theme boot metadata.'),

            // Installer/Marketplace boot process state.
            PatchRegistry::class => self::boot('Installer patches are registered during provider boot.'),

            // Core wrappers around boot registries or stateless collaborators.
            EventSourcedRegistry::class => self::boot('Event-sourced model definitions are package boot registrations.'),
            DatabaseBackupDriverRegistry::class => self::boot('Backup driver definitions are package boot registrations.'),
            MakerRegistry::class => self::boot('Maker definitions are package boot registrations.'),
            PackageSurfaceRegistrar::class => self::boot('The registrar delegates only to boot-lifetime package surface registries.'),
            LocalAppThemeDefinitionRepository::class => self::stateless('The repository retains filesystem collaborators but no operation-derived values.'),
        ];
    }

    /** @return array<string, non-empty-string> */
    public static function mutableStaticState(): array
    {
        return [
            HasModelRelations::class => 'This trait provides a deliberate boot registry shared by every operation.',
            ExtensibleModel::class => 'Extension fillable and cast declarations are deliberate model boot registries.',
            ManifestLoader::class => 'Registered manifest autoload paths are process boot metadata and prevent duplicate Composer loaders.',
            SanitizeSiteSpecSectionHtmlAction::class => 'The sanitizer is an immutable process-wide parser cache.',
            DemoPackageAction::class => 'The static process factory is a test-only override with an explicit reset API; production operations never populate it.',
            InstallDeveloperToolingAction::class => 'Static collaborators and paths are test-only overrides with explicit reset APIs; production operations never populate them.',
            RequirePackageAction::class => 'The static process factory is a test-only override with an explicit reset API; production operations never populate it.',
        ];
    }

    /** @return array{lifetime: SingletonLifetime, protection: 'boot', reason: non-empty-string} */
    private static function boot(string $reason): array
    {
        throw_if($reason === '', InvalidArgumentException::class, 'A singleton lifetime classification requires a reason.');

        return ['lifetime' => SingletonLifetime::BootImmutable, 'protection' => 'boot', 'reason' => $reason];
    }

    /** @return array{lifetime: SingletonLifetime, protection: 'tagged', reason: non-empty-string} */
    private static function tagged(string $reason): array
    {
        throw_if($reason === '', InvalidArgumentException::class, 'A singleton lifetime classification requires a reason.');

        return ['lifetime' => SingletonLifetime::RequestMutable, 'protection' => 'tagged', 'reason' => $reason];
    }

    /** @return array{lifetime: SingletonLifetime, protection: 'delegated', reason: non-empty-string} */
    private static function delegated(string $reason): array
    {
        throw_if($reason === '', InvalidArgumentException::class, 'A singleton lifetime classification requires a reason.');

        return ['lifetime' => SingletonLifetime::RequestMutable, 'protection' => 'delegated', 'reason' => $reason];
    }

    /** @return array{lifetime: SingletonLifetime, protection: 'boot', reason: non-empty-string} */
    private static function stateless(string $reason): array
    {
        throw_if($reason === '', InvalidArgumentException::class, 'A singleton lifetime classification requires a reason.');

        return ['lifetime' => SingletonLifetime::Stateless, 'protection' => 'boot', 'reason' => $reason];
    }
}
