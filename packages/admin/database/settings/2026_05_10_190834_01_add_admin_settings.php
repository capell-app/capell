<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Admin settings
        if (! $this->migrator->exists('admin.html_editor')) {
            $this->migrator->add('admin.html_editor', 'RichEditor');
        }

        if (! $this->migrator->exists('admin.sidebar_collapsible')) {
            $this->migrator->add('admin.sidebar_collapsible', 'collapsible');
        }

        if (! $this->migrator->exists('admin.form_action_position')) {
            $this->migrator->add('admin.form_action_position', 'below_form');
        }

        if (! $this->migrator->exists('admin.show_helper_tooltips')) {
            $this->migrator->add('admin.show_helper_tooltips', true);
        }

        if (! $this->migrator->exists('admin.show_configurator_path_hints')) {
            $this->migrator->add('admin.show_configurator_path_hints', false);
        }

        if (! $this->migrator->exists('admin.hide_info_banner')) {
            $this->migrator->add('admin.hide_info_banner', false);
        }

        if (! $this->migrator->exists('admin.enable_import_export')) {
            $this->migrator->add('admin.enable_import_export', true);
        }

        if (! $this->migrator->exists('admin.show_resource_statistics')) {
            $this->migrator->add('admin.show_resource_statistics', true);
        }

        if (! $this->migrator->exists('admin.enable_activity_timeline')) {
            $this->migrator->add('admin.enable_activity_timeline', true);
        }

        if (! $this->migrator->exists('admin.enable_login_audit_user_bridge')) {
            $this->migrator->add('admin.enable_login_audit_user_bridge', true);
        }

        if (! $this->migrator->exists('admin.enable_publishing_studio_user_bridge')) {
            $this->migrator->add('admin.enable_publishing_studio_user_bridge', true);
        }

        if (! $this->migrator->exists('admin.enable_agent_bridge_user_bridge')) {
            $this->migrator->add('admin.enable_agent_bridge_user_bridge', true);
        }

        if (! $this->migrator->exists('admin.enable_security_access_user_bridge')) {
            $this->migrator->add('admin.enable_security_access_user_bridge', true);
        }

        if (! $this->migrator->exists('admin.enable_content_ownership_user_bridge')) {
            $this->migrator->add('admin.enable_content_ownership_user_bridge', true);
        }

        if (! $this->migrator->exists('admin.enable_support_actions_user_bridge')) {
            $this->migrator->add('admin.enable_support_actions_user_bridge', true);
        }

        // Dashboard settings
        if (! $this->migrator->exists('admin.enabled_widgets')) {
            $this->migrator->add('admin.enabled_widgets', [
                'site_stats_overview' => true,
                'account' => true,
                'workspace_activity' => true,
                'my_work_queue' => true,
                'recently_published' => true,
                'recent_activity' => true,
                'list_pages' => true,
                'site_health' => true,
                'page_status' => true,
            ]);
        }

        if (! $this->migrator->exists('admin.widget_order')) {
            $this->migrator->add('admin.widget_order', [
                'account' => 0,
                'filament_info' => 10,
                'list_pages' => 20,
                'recent_activity' => 30,
                'update_advisories' => 40,
                'marketing_studio.quick_actions' => 50,
                'marketing_studio.work_queue' => 51,
                'marketing_studio.launch_readiness' => 52,
                'marketing_studio.timeline' => 53,
                'marketing_studio.advanced' => 54,
                'extensions.stats' => 100,
                'extensions.health' => 110,
                'extensions.available_actions' => 120,
                'extensions.installed' => 130,
            ]);
        }

        if (! $this->migrator->exists('admin.my_work_queue_limit')) {
            $this->migrator->add('admin.my_work_queue_limit', 15);
        }

        if (! $this->migrator->exists('admin.recently_published_limit')) {
            $this->migrator->add('admin.recently_published_limit', 10);
        }

        if (! $this->migrator->exists('admin.cache_health_refresh_interval_seconds')) {
            $this->migrator->add('admin.cache_health_refresh_interval_seconds', 60);
        }

        if (! $this->migrator->exists('admin.ai_orchestrator_spend_window_days')) {
            $this->migrator->add('admin.ai_orchestrator_spend_window_days', 30);
        }
    }
};
