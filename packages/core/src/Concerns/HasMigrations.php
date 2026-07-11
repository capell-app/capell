<?php

declare(strict_types=1);

namespace Capell\Core\Concerns;

trait HasMigrations
{
    /**
     * @return list<string>
     */
    public static function getMigrations(): array
    {
        return [
            '2026_05_10_190832_01_create_audits_table',
            '2026_05_10_190832_02_create_languages_table',
            '2026_05_10_190832_03_create_blueprints_table',
            '2026_05_10_190832_04_create_themes_table',
            '2026_05_10_190832_05_create_sites_table',
            '2026_05_10_190832_06_create_site_domains_table',
            '2026_05_10_190832_07_create_translations_table',
            '2026_05_10_190832_08_create_layouts_table',
            '2026_05_10_190832_12_create_pages_table',
            '2026_05_10_190832_13_create_page_urls_table',
            '2026_05_10_190832_14_create_redirect_health_snapshots_table',
            '2026_05_10_190832_15_create_asset_attachments_table',
            '2026_05_10_190832_16_create_content_graph_edges_table',
            '2026_05_10_190832_17_add_collection_index_to_media_table',
            '2026_05_10_190832_18_add_bio_to_users_table',
            '2026_05_10_190832_19_add_team_id_to_permission_tables',
            '2026_05_10_190832_20_create_page_role_restrictions_table',
            '2026_05_10_190832_21_add_dismissed_hints_to_users_table',
            '2026_05_10_190832_22_add_preferred_admin_language_to_users_table',
            '2026_05_10_190832_23_create_capell_upgrade_log_table',
            '2026_05_10_190832_24_create_capell_extensions_table',
            '2026_05_10_190832_26_create_capell_marketplace_installs_table',
            '2026_05_10_190832_27_create_capell_extension_health_alerts_table',
            '2026_05_10_190832_28_create_capell_admin_notification_subscriptions_table',
            '2026_05_26_000001_create_capell_upgrade_runs_table',
            '2026_05_26_000002_create_capell_upgrade_run_events_table',
            '2026_05_29_000001_create_deletion_batches_table',
            '2026_05_31_000001_create_content_locks_table',
            '2026_06_13_000001_create_capell_public_render_contract_events_table',
            '2026_06_24_000001_change_pages_visible_columns_to_datetime',
            '2026_06_25_000001_add_deleted_at_to_media_table',
            '2026_06_25_000002_add_deleted_at_to_translations_table',
            '2026_06_25_000004_create_layout_content_snapshots_table',
            '2026_06_25_000005_create_blueprint_schema_snapshots_table',
            '2026_06_25_000006_create_block_templates_table',
            '2026_06_25_000007_add_content_structure_override_to_pages_table',
            '2026_06_26_000001_create_stored_events_table',
            '2026_06_26_000002_create_snapshots_table',
            '2026_06_26_000003_create_page_workflow_states_table',
            '2026_06_26_000004_create_page_revisions_table',
            '2026_06_26_000005_drop_legacy_page_content_snapshots_table',
        ];
    }

    /**
     * @return list<string>
     */
    public static function getSettingMigrations(): array
    {
        return [
            '2026_05_10_190833_01_add_locale_settings',
            '2026_05_10_190833_02_create_theme_studio_settings',
            '2026_05_23_180001_add_image_source_settings',
        ];
    }
}
