<?php

declare(strict_types=1);

namespace Capell\Admin\Enums;

use Capell\Admin\Filament\Pages\ExtensionsPage;
use Capell\Admin\Filament\Pages\MarketingStudioPage;
use Capell\Admin\Filament\Pages\SettingsPage;
use Capell\Admin\Filament\Pages\SiteAdminMetricsPage;
use Capell\Admin\Filament\Pages\SiteHealthPage;
use Capell\Admin\Filament\Pages\SitemapPage;
use Capell\Admin\Filament\Pages\UpgradePage;

enum PageEnum: string
{
    case Extension = ExtensionsPage::class;

    case MarketingStudio = MarketingStudioPage::class;

    case SiteHealth = SiteHealthPage::class;

    case SiteAdminMetrics = SiteAdminMetricsPage::class;

    case SettingsPage = SettingsPage::class;

    case SitemapPage = SitemapPage::class;

    case UpgradePage = UpgradePage::class;

}
