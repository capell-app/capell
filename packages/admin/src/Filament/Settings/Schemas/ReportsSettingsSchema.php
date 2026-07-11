<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Settings\Schemas;

use Capell\Admin\Filament\Contracts\HasSchema;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

final class ReportsSettingsSchema implements HasSchema
{
    /**
     * @return list<Component>
     */
    public static function make(Schema $schema): array
    {
        return [
            Section::make(__('capell-admin::reports.settings_heading'))
                ->columnSpanFull()
                ->description(__('capell-admin::reports.settings_description'))
                ->schema([
                    Repeater::make('report_visibility')
                        ->label(__('capell-admin::reports.roles'))
                        ->helperText(__('capell-admin::reports.roles_helper'))
                        ->addable(false)
                        ->deletable(false)
                        ->reorderable(false)
                        ->defaultItems(0)
                        ->itemLabel(fn (array $state): string => is_string($state['role_label'] ?? null)
                            ? $state['role_label']
                            : (string) __('capell-admin::reports.role'))
                        ->schema([
                            Hidden::make('role_name')
                                ->dehydrated(),
                            Hidden::make('role_label')
                                ->dehydrated(),
                            Repeater::make('reports')
                                ->label(__('capell-admin::reports.reports'))
                                ->helperText(__('capell-admin::reports.reports_helper'))
                                ->addable(false)
                                ->deletable(false)
                                ->reorderable(false)
                                ->defaultItems(0)
                                ->itemLabel(fn (array $state): string => is_string($state['report_label'] ?? null)
                                    ? $state['report_label']
                                    : (string) __('capell-admin::reports.report'))
                                ->schema([
                                    Hidden::make('report_key')
                                        ->dehydrated(),
                                    Hidden::make('report_label')
                                        ->dehydrated(),
                                    Hidden::make('report_description')
                                        ->dehydrated(),
                                    Toggle::make('enabled')
                                        ->label(fn (Get $get): string => is_string($get('report_label'))
                                            ? $get('report_label')
                                            : (string) __('capell-admin::reports.enabled'))
                                        ->helperText(fn (Get $get): ?string => is_string($get('report_description'))
                                            ? $get('report_description')
                                            : null),
                                ])
                                ->columns(1),
                        ])
                        ->columns(1),
                ]),
        ];
    }
}
