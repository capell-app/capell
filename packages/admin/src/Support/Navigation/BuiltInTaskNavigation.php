<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Navigation;

use Capell\Admin\Filament\Pages\AnalyticsDashboard;
use Capell\Admin\Filament\Pages\CapellDashboard;
use Capell\Admin\Filament\Pages\DashboardToolsPage;
use Capell\Admin\Filament\Pages\ExtensionsPage;
use Capell\Admin\Filament\Pages\RecentlyDeletedPage;
use Capell\Admin\Filament\Pages\Reports\AccessibilityReadinessReport;
use Capell\Admin\Filament\Pages\Reports\DemoInstallHealthReport;
use Capell\Admin\Filament\Pages\Reports\PackageReadinessReport;
use Capell\Admin\Filament\Pages\Reports\PublicRenderSafetyReport;
use Capell\Admin\Filament\Pages\Reports\PublishingReadinessReport;
use Capell\Admin\Filament\Pages\SettingsPage;
use Capell\Admin\Filament\Pages\SiteAdminMetricsPage;
use Capell\Admin\Filament\Pages\SiteHealthPage;
use Capell\Admin\Filament\Pages\SitemapPage;
use Capell\Admin\Filament\Pages\UpgradePage;
use Capell\Admin\Filament\Resources\Activities\ActivityResource;
use Capell\Admin\Filament\Resources\BlockTemplates\BlockTemplateResource;
use Capell\Admin\Filament\Resources\Blueprints\BlueprintResource;
use Capell\Admin\Filament\Resources\Languages\LanguageResource;
use Capell\Admin\Filament\Resources\Layouts\LayoutResource;
use Capell\Admin\Filament\Resources\Media\MediaResource;
use Capell\Admin\Filament\Resources\Pages\PageResource;
use Capell\Admin\Filament\Resources\PageUrls\PageUrlResource;
use Capell\Admin\Filament\Resources\Redirects\RedirectResource;
use Capell\Admin\Filament\Resources\Roles\RoleResource;
use Capell\Admin\Filament\Resources\Sites\SiteResource;
use Capell\Admin\Filament\Resources\Themes\ThemeResource;
use Capell\Admin\Filament\Resources\Users\UserResource;

final class BuiltInTaskNavigation
{
    public function groupFor(string $key): ?string
    {
        return match ($key) {
            CapellDashboard::class, PageResource::class, PageUrlResource::class, RecentlyDeletedPage::class => (string) __('capell-admin::navigation.task_group_content'),
            MediaResource::class => (string) __('capell-admin::navigation.task_group_media'),
            LayoutResource::class, ThemeResource::class, BlueprintResource::class, BlockTemplateResource::class,
            SiteResource::class, RedirectResource::class, SitemapPage::class, SiteHealthPage::class => (string) __('capell-admin::navigation.task_group_site'),
            UserResource::class, RoleResource::class, ActivityResource::class => (string) __('capell-admin::navigation.task_group_people'),
            LanguageResource::class, SettingsPage::class, ExtensionsPage::class, UpgradePage::class => (string) __('capell-admin::navigation.task_group_settings'),
            AnalyticsDashboard::class, DashboardToolsPage::class, SiteAdminMetricsPage::class,
            AccessibilityReadinessReport::class, DemoInstallHealthReport::class, PackageReadinessReport::class,
            PublicRenderSafetyReport::class, PublishingReadinessReport::class => (string) __('capell-admin::navigation.task_group_reports'),
            default => null,
        };
    }
}
