<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Settings;

use Capell\Admin\Enums\AdminFormActionPositionEnum;
use Capell\Admin\Enums\SidebarCollapseEnum;
use Capell\Admin\Filament\Components\Forms\HtmlEditorSelect;
use Capell\Admin\Filament\Contracts\HasSchema;
use Capell\Admin\Filament\Support\HelperText;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AdminSettingsSchema implements HasSchema
{
    public static function make(Schema $schema): array
    {
        return [
            Section::make(__('capell-admin::generic.admin_settings'))
                ->columnSpanFull()
                ->description(__('capell-admin::generic.admin_settings_description'))
                ->schema([
                    Grid::make(2)
                        ->schema([
                            HelperText::apply(
                                HtmlEditorSelect::make('html_editor'),
                                'capell-admin::form.html_editor_helper',
                            ),
                            HelperText::apply(
                                ToggleButtons::make('sidebar_collapsible')
                                    ->label(__('capell-admin::form.sidebar_collapsible'))
                                    ->enum(SidebarCollapseEnum::class)
                                    ->inline(),
                                'capell-admin::form.sidebar_collapsible_helper',
                            ),
                            HelperText::apply(
                                ToggleButtons::make('form_action_position')
                                    ->label(__('capell-admin::form.form_action_position'))
                                    ->enum(AdminFormActionPositionEnum::class)
                                    ->inline(),
                                'capell-admin::form.form_action_position_helper',
                            ),
                            HelperText::apply(
                                Toggle::make('show_helper_tooltips')
                                    ->label(__('capell-admin::form.show_helper_tooltips')),
                                'capell-admin::form.show_helper_tooltips_helper',
                            ),
                            HelperText::apply(
                                Toggle::make('show_configurator_path_hints')
                                    ->label(__('capell-admin::form.show_configurator_path_hints')),
                                'capell-admin::form.show_configurator_path_hints_helper',
                            ),
                        ]),
                ]),
            Section::make(__('capell-admin::generic.interface'))
                ->columnSpanFull()
                ->description(__('capell-admin::generic.admin_interface_description'))
                ->schema([
                    Grid::make(2)
                        ->schema([
                            HelperText::apply(
                                Toggle::make('hide_info_banner')
                                    ->label(__('capell-admin::form.hide_info_banner')),
                                'capell-admin::form.hide_info_banner_helper',
                            ),
                            HelperText::apply(
                                Toggle::make('enable_import_export')
                                    ->label(__('capell-admin::form.enable_import_export')),
                                'capell-admin::form.enable_import_export_helper',
                            ),
                            HelperText::apply(
                                Toggle::make('show_resource_statistics')
                                    ->label(__('capell-admin::form.show_resource_statistics')),
                                'capell-admin::form.show_resource_statistics_helper',
                            ),
                            HelperText::apply(
                                Toggle::make('enable_activity_timeline')
                                    ->label(__('capell-admin::form.enable_activity_timeline')),
                                'capell-admin::form.enable_activity_timeline_helper',
                            ),
                            HelperText::apply(
                                Toggle::make('enable_header_navigation_tree')
                                    ->label(__('capell-admin::form.enable_header_navigation_tree')),
                                'capell-admin::form.enable_header_navigation_tree_helper',
                            ),
                        ]),
                ]),
            Section::make(__('capell-admin::generic.user_resource_bridges'))
                ->columnSpanFull()
                ->description(__('capell-admin::generic.user_resource_bridges_description'))
                ->schema([
                    Grid::make(2)
                        ->schema([
                            HelperText::apply(
                                Toggle::make('enable_login_audit_user_bridge')
                                    ->label(__('capell-admin::form.enable_login_audit_user_bridge')),
                                'capell-admin::form.enable_login_audit_user_bridge_helper',
                            ),
                            HelperText::apply(
                                Toggle::make('enable_publishing_studio_user_bridge')
                                    ->label(__('capell-admin::form.enable_publishing_studio_user_bridge')),
                                'capell-admin::form.enable_publishing_studio_user_bridge_helper',
                            ),
                            HelperText::apply(
                                Toggle::make('enable_agent_bridge_user_bridge')
                                    ->label(__('capell-admin::form.enable_agent_bridge_user_bridge')),
                                'capell-admin::form.enable_agent_bridge_user_bridge_helper',
                            ),
                            HelperText::apply(
                                Toggle::make('enable_security_access_user_bridge')
                                    ->label(__('capell-admin::form.enable_security_access_user_bridge')),
                                'capell-admin::form.enable_security_access_user_bridge_helper',
                            ),
                            HelperText::apply(
                                Toggle::make('enable_content_ownership_user_bridge')
                                    ->label(__('capell-admin::form.enable_content_ownership_user_bridge')),
                                'capell-admin::form.enable_content_ownership_user_bridge_helper',
                            ),
                            HelperText::apply(
                                Toggle::make('enable_support_actions_user_bridge')
                                    ->label(__('capell-admin::form.enable_support_actions_user_bridge')),
                                'capell-admin::form.enable_support_actions_user_bridge_helper',
                            ),
                        ]),
                ]),
        ];
    }
}
