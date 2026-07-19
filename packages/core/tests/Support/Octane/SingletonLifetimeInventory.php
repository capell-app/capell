<?php

declare(strict_types=1);

namespace Capell\Tests\Support\Octane;

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
            'Capell\Core\ThemeStudio\Preview\ThemePreviewContext' => 'Capell\Core\ThemeStudio\Preview\ThemePreviewContext',
            'Capell\Frontend\Contracts\FrontendContextReader' => 'Capell\Frontend\Support\State\FrontendState',
        ];
    }

    /**
     * @return array<class-string, array{lifetime: SingletonLifetime, protection: 'boot'|'tagged'|'delegated', reason: non-empty-string}>
     */
    public static function mutableSingletons(): array
    {
        return [
            // Core boot registration state.
            'Capell\Core\Support\PackageRegistry\CapellPackageRegistry' => self::boot('Package manifests are discovered once and invalidated only by explicit package mutation.'),
            'Capell\Core\Support\Models\ModelInterceptorRegistry' => self::boot('Model interceptors are package boot registrations.'),
            'Capell\Core\Support\Subscriber\SubscriberRegistry' => self::boot('Subscribers are package boot registrations.'),
            'Capell\Core\Support\Renderables\RenderableRegistry' => self::boot('Renderable types are package boot registrations.'),
            'Capell\Core\Support\Links\LinkableContentRegistry' => self::boot('Linkable content types are package boot registrations.'),
            'Capell\Core\Support\ContentGraph\ContentGraphRegistry' => self::boot('Content graph nodes and edges are package boot registrations.'),
            'Capell\Core\Support\Themes\ThemeChromeRegistry' => self::boot('Theme chrome definitions are package boot registrations.'),
            'Capell\Core\Support\Themes\ThemeInstallDefaultsRegistry' => self::boot('Theme install defaults are package boot registrations.'),
            'Capell\Core\Support\Install\InstallPatchRegistry' => self::boot('Install patches are package boot registrations.'),
            'Capell\Core\Support\Presentation\PresentationPresetRegistry' => self::boot('Presentation presets are package boot registrations.'),
            'Capell\Core\Support\Assets\VendorAssetConditionRegistry' => self::boot('Vendor asset conditions are package boot registrations.'),
            'Capell\Core\ThemeStudio\Theme\ThemeRegistry' => self::boot('Themes are package boot registrations.'),
            'Capell\Core\ThemeStudio\Theme\PagePresentationRegistry' => self::boot('Page presentation definitions are package boot registrations.'),
            'Capell\Core\ThemeStudio\Theme\WidgetPresentationRegistry' => self::boot('Widget presentation definitions are package boot registrations.'),
            'Capell\Core\ThemeStudio\Assets\ThemeTokenStore' => self::boot('Theme tokens are package boot registrations.'),
            'Capell\Core\Support\CapellCoreManager' => self::tagged('Boot registrations persist while the manager selectively flushes only operation-derived default-page state.'),
            'Capell\Core\Support\Components\ComponentRegistry' => self::tagged('Boot component registrations and namespaces persist while discovered components and the cache-presence memo are selectively flushed.'),
            'Capell\Core\Support\Cache\CapellCacheManager' => self::delegated('The core manager flushes this per-operation in-memory cache.'),
            'Capell\Core\Support\Security\LockdownStore' => self::tagged('Lockdown decisions are operation-derived and explicitly flushed.'),
            'Capell\Core\EventSourcing\Rollback\RollbackValidatorRegistry' => self::boot('Rollback validators are package boot registrations.'),
            'Capell\Core\Support\Settings\SettingsSchemaRegistry' => self::boot('Settings schemas and metadata are package boot registrations.'),

            // Admin boot registration state.
            'Capell\Admin\Support\Extensions\ExtensionPageRegistry' => self::boot('Extension pages are package boot registrations.'),
            'Capell\Admin\Support\Notifications\AdminNotificationGroupRegistry' => self::boot('Notification groups are package boot registrations.'),
            'Capell\Admin\Support\Activity\ActivityResourceLinkRegistry' => self::boot('Activity resource links are package boot registrations.'),
            'Capell\Admin\Support\AdminSurfaceContributionRegistry' => self::boot('Admin surfaces are package boot registrations.'),
            'Capell\Admin\Support\Reports\ReportRegistry' => self::boot('Reports are package boot registrations.'),
            'Capell\Admin\Support\Dashboard\DashboardFilamentWidgetRegistry' => self::boot('Dashboard widgets are package boot registrations.'),
            'Capell\Admin\Support\MarketingStudio\MarketingStudioActionRegistry' => self::boot('Marketing actions are package boot registrations.'),
            'Capell\Admin\Support\UserMenu\UserMenuItemRegistry' => self::boot('User menu definitions are package boot registrations; resolution is scoped.'),
            'Capell\Admin\Support\Dashboard\OverviewStatRegistry' => self::boot('Overview stats are package boot registrations.'),
            'Capell\Admin\Support\Bridges\AdminBridgeRegistry' => self::boot('Admin bridge contributions are package boot registrations.'),
            'Capell\Admin\Support\ImportEntryRegistry' => self::boot('Import entries are package boot registrations.'),
            'Capell\Admin\Support\Extensions\ExtensionManagementSurfaceRegistry' => self::boot('Extension management surfaces are package boot registrations.'),
            'Capell\Admin\Support\Extensions\ExtensionsPageActionRegistry' => self::boot('Extension page actions are package boot registrations.'),
            'Capell\Admin\Support\AdminEventRegistry' => self::boot('Admin events are package boot registrations.'),
            'Capell\Admin\Support\CapellAdminManager' => self::boot('The manager delegates to boot registries and does not retain operation payloads.'),
            'Capell\Admin\Support\Widgets\WidgetDiscovery' => self::boot('Widget sources and authoritative widget definitions are discovered from package boot registrations.'),
            'Capell\Admin\Support\AdminEventRouter' => self::boot('The router reads the boot-lifetime admin event registry and does not retain operation payloads.'),
            'Capell\Admin\Support\Bridges\AdminBridgeRegistrar' => self::boot('The registrar delegates only to boot-lifetime bridge and settings registries.'),
            'Capell\Admin\Support\Install\AdminPermissionSynchronizer' => self::stateless('The synchronizer retains collaborators but no operation-derived values.'),

            // Frontend boot registration state and one reset participant.
            'Capell\Frontend\Support\Assets\FrontendAssetsService' => self::boot('Asset declarations are package boot registrations.'),
            'Capell\Frontend\Support\Components\FrontendComponentRegistry' => self::boot('Frontend components are package boot registrations.'),
            'Capell\Frontend\Support\Links\PublicRouteAliasRegistry' => self::boot('Public route aliases are package boot registrations.'),
            'Capell\Frontend\Support\Renderables\RenderableDynamicDataRegistry' => self::boot('Dynamic data resolvers are package boot registrations.'),
            'Capell\Frontend\Support\Render\RenderHookRegistry' => self::boot('Render hooks are package boot registrations.'),
            'Capell\Frontend\Support\Rules\FrontendRuleConditionRegistry' => self::boot('Rule conditions are package boot registrations.'),
            'Capell\Frontend\Support\Routing\ReservedFrontendPathRegistry' => self::boot('Reserved paths are package boot registrations.'),
            'Capell\Frontend\Support\Routing\ReservedFrontendDomainRegistry' => self::boot('Reserved domains are package boot registrations.'),
            'Capell\Frontend\Support\View\ThemeViewRegistrar' => self::tagged('View finder namespace hints are restored after every operation.'),
            'Capell\Frontend\Support\Routing\FrontendRouteMiddlewareRegistry' => self::boot('Route middleware declarations are package boot registrations.'),
            'Capell\Frontend\Support\Assets\FrontendResourceRegistry' => self::boot('Frontend resources are package boot registrations.'),
            'Capell\Frontend\Support\Assets\FrontendPackageDependencyRegistry' => self::boot('Frontend package dependencies are package boot registrations.'),
            'Capell\Frontend\Support\Assets\FrontendViteInputRegistry' => self::boot('Vite inputs are package boot registrations.'),
            'Capell\Frontend\Support\Cache\CacheInvalidationDependencyRegistry' => self::boot('Invalidation dependencies are package boot registrations.'),
            'Capell\Frontend\Support\Assets\DefaultFrontendResourcePlanRenderer' => self::stateless('The renderer retains collaborators but no operation-derived values.'),
            'Capell\Frontend\Support\Render\FrontendHookRegistrar' => self::boot('The registrar delegates only to the boot-lifetime render hook registry.'),
            'Capell\Frontend\Support\Routing\ReservedFrontendRequest' => self::boot('The predicate reads boot-lifetime reserved path and domain registries.'),
            'Capell\Frontend\Support\View\ThemeChainResolver' => self::boot('Theme-chain inputs are package and theme boot metadata.'),

            // Installer/Marketplace boot process state.
            'Capell\Installer\Support\InstallGuide\PatchRegistry' => self::boot('Installer patches are registered during provider boot.'),

            // Core wrappers around boot registries or stateless collaborators.
            'Capell\Core\EventSourcing\Support\EventSourcedRegistry' => self::boot('Event-sourced model definitions are package boot registrations.'),
            'Capell\Core\Support\Backup\DatabaseBackupDriverRegistry' => self::boot('Backup driver definitions are package boot registrations.'),
            'Capell\Core\Support\Makers\MakerRegistry' => self::boot('Maker definitions are package boot registrations.'),
            'Capell\Core\Support\Packages\PackageSurfaceRegistrar' => self::boot('The registrar delegates only to boot-lifetime package surface registries.'),
            'Capell\Core\ThemeStudio\Discovery\LocalAppThemeDefinitionRepository' => self::stateless('The repository retains filesystem collaborators but no operation-derived values.'),
        ];
    }

    /** @return array<string, non-empty-string> */
    public static function mutableStaticState(): array
    {
        return [
            'Capell\Core\Concerns\HasModelRelations' => 'This trait provides a deliberate boot registry shared by every operation.',
            'Capell\Core\Models\Concerns\ExtensibleModel' => 'Extension fillable and cast declarations are deliberate model boot registries.',
            'Capell\Core\Support\Manifest\ManifestLoader' => 'Registered manifest autoload paths are process boot metadata and prevent duplicate Composer loaders.',
            'Capell\Core\Actions\SanitizeSiteSpecSectionHtmlAction' => 'The sanitizer is an immutable process-wide parser cache.',
            'Capell\Frontend\Actions\RenderHtmlContentAction' => 'The sanitizer is an immutable process-wide parser cache.',
            'Capell\Core\Actions\DemoPackageAction' => 'The static process factory is a test-only override with an explicit reset API; production operations never populate it.',
            'Capell\Core\Actions\Install\InstallDeveloperToolingAction' => 'Static collaborators and paths are test-only overrides with explicit reset APIs; production operations never populate them.',
            'Capell\Core\Actions\RequirePackageAction' => 'The static process factory is a test-only override with an explicit reset API; production operations never populate it.',
        ];
    }

    /** @return array{lifetime: SingletonLifetime, protection: 'boot', reason: non-empty-string} */
    private static function boot(string $reason): array
    {
        if ($reason === '') {
            throw new InvalidArgumentException('A singleton lifetime classification requires a reason.');
        }

        return ['lifetime' => SingletonLifetime::BootImmutable, 'protection' => 'boot', 'reason' => $reason];
    }

    /** @return array{lifetime: SingletonLifetime, protection: 'tagged', reason: non-empty-string} */
    private static function tagged(string $reason): array
    {
        if ($reason === '') {
            throw new InvalidArgumentException('A singleton lifetime classification requires a reason.');
        }

        return ['lifetime' => SingletonLifetime::RequestMutable, 'protection' => 'tagged', 'reason' => $reason];
    }

    /** @return array{lifetime: SingletonLifetime, protection: 'delegated', reason: non-empty-string} */
    private static function delegated(string $reason): array
    {
        if ($reason === '') {
            throw new InvalidArgumentException('A singleton lifetime classification requires a reason.');
        }

        return ['lifetime' => SingletonLifetime::RequestMutable, 'protection' => 'delegated', 'reason' => $reason];
    }

    /** @return array{lifetime: SingletonLifetime, protection: 'boot', reason: non-empty-string} */
    private static function stateless(string $reason): array
    {
        if ($reason === '') {
            throw new InvalidArgumentException('A singleton lifetime classification requires a reason.');
        }

        return ['lifetime' => SingletonLifetime::Stateless, 'protection' => 'boot', 'reason' => $reason];
    }
}
