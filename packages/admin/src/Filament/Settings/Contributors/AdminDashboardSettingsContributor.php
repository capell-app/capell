<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Settings\Contributors;

use Capell\Admin\Contracts\DashboardSettingsContributor;

final class AdminDashboardSettingsContributor implements DashboardSettingsContributor
{
    /**
     * @return list<array{key: string, label: string, group: string, description: string}>
     */
    public function settingsKeys(): array
    {
        return [
            ['key' => 'site_stats_overview', 'label' => __('capell-admin::dashboard.widget_site_stats'), 'group' => 'Core', 'description' => __('capell-admin::dashboard.widget_site_stats_description')],
            ['key' => 'account', 'label' => __('capell-admin::dashboard.widget_account'), 'group' => 'Core', 'description' => __('capell-admin::dashboard.widget_account_description')],
            ['key' => 'page_status', 'label' => __('capell-admin::dashboard.widget_capell_overview'), 'group' => 'Core', 'description' => __('capell-admin::dashboard.widget_page_status_description')],
            ['key' => 'my_work_queue', 'label' => __('capell-admin::dashboard.widget_my_work_queue'), 'group' => 'Editor', 'description' => __('capell-admin::dashboard.widget_my_work_queue_description')],
            ['key' => 'recently_published', 'label' => __('capell-admin::dashboard.widget_recently_published'), 'group' => 'Editor', 'description' => __('capell-admin::dashboard.widget_recently_published_description')],
            ['key' => 'recent_activity', 'label' => __('capell-admin::dashboard.widget_recent_activity'), 'group' => 'Admin', 'description' => __('capell-admin::dashboard.widget_recent_activity_description')],
            ['key' => 'list_pages', 'label' => __('capell-admin::dashboard.widget_list_pages'), 'group' => 'Admin', 'description' => __('capell-admin::dashboard.widget_list_pages_description')],
            ['key' => 'update_advisories', 'label' => __('capell-admin::dashboard.widget_update_advisories'), 'group' => 'System', 'description' => __('capell-admin::dashboard.widget_update_advisories_description')],
        ];
    }
}
