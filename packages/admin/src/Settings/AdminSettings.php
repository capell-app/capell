<?php

declare(strict_types=1);

namespace Capell\Admin\Settings;

use Capell\Admin\Contracts\SettingsSchemaContract;
use Capell\Admin\Data\Reports\ReportDefinitionData;
use Capell\Admin\Enums\AdminFormActionPositionEnum;
use Capell\Admin\Enums\EditorEnum;
use Capell\Admin\Enums\SidebarCollapseEnum;
use Capell\Admin\Filament\Settings\AdminSettingsSchema;
use Capell\Core\Contracts\SettingsContract;
use Override;
use Spatie\LaravelSettings\Settings;

class AdminSettings extends Settings implements SettingsContract, SettingsSchemaContract
{
    public EditorEnum $html_editor = EditorEnum::RichEditor;

    public SidebarCollapseEnum $sidebar_collapsible = SidebarCollapseEnum::None;

    public AdminFormActionPositionEnum $form_action_position = AdminFormActionPositionEnum::BelowForm;

    public bool $show_helper_tooltips = true;

    public bool $show_configurator_path_hints = false;

    public bool $hide_info_banner = false;

    public bool $enable_import_export = true;

    public bool $show_resource_statistics = true;

    public bool $enable_activity_timeline = true;

    public bool $enable_header_navigation_tree = true;

    public bool $enable_login_audit_user_bridge = true;

    public bool $enable_publishing_studio_user_bridge = true;

    public bool $enable_agent_bridge_user_bridge = true;

    public bool $enable_security_access_user_bridge = true;

    public bool $enable_content_ownership_user_bridge = true;

    public bool $enable_support_actions_user_bridge = true;

    /** @var array<string, bool> */
    public array $enabled_widgets = [];

    /** @var array<string, int> */
    public array $widget_order = [];

    /** @phpstan-var array<string, array<string, bool>> */
    public array $enabled_reports_by_role = [];

    public int $my_work_queue_limit = 15;

    public int $recently_published_limit = 10;

    public int $cache_health_refresh_interval_seconds = 60;

    public int $ai_orchestrator_spend_window_days = 30;

    public static function group(): string
    {
        return 'admin';
    }

    public static function schema(): string
    {
        return AdminSettingsSchema::class;
    }

    public static function instance(): self
    {
        return resolve(self::class);
    }

    /**
     * @return array<string, int>
     */
    public static function defaultWidgetOrder(): array
    {
        return [
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
        ];
    }

    #[Override]
    public function refresh(): self
    {
        parent::refresh();

        return $this;
    }

    public function isWidgetEnabled(string $settingsKey): bool
    {
        return $this->enabled_widgets[$settingsKey] ?? true;
    }

    public function sortOrderFor(string $settingsKey): int
    {
        return $this->widget_order[$settingsKey] ?? 999;
    }

    public function isReportEnabledForRole(string $roleName, ReportDefinitionData|string $report): bool
    {
        $reportKey = $report instanceof ReportDefinitionData ? $report->settingsKey() : $report;
        $defaultEnabled = $report instanceof ReportDefinitionData ? $report->defaultEnabled : true;

        $roleReports = $this->enabled_reports_by_role[$roleName] ?? null;

        if (! is_array($roleReports)) {
            return $defaultEnabled;
        }

        $enabled = $roleReports[$reportKey] ?? null;

        return is_bool($enabled) ? $enabled : $defaultEnabled;
    }
}
