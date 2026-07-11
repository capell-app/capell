<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms\Presentation;

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Section;

class ResourceSettingsSchema
{
    /**
     * @return array<int, mixed>
     */
    public static function make(string $statePath = '__capell.resources'): array
    {
        return [
            Section::make(__('capell-admin::form.frontend_resources'))
                ->icon('heroicon-o-circle-stack')
                ->description(__('capell-admin::generic.frontend_resources_description'))
                ->statePath($statePath)
                ->dehydrated(fn (?array $state): bool => self::hasResourceState($state))
                ->collapsed()
                ->compact()
                ->columns(['default' => 1, 'md' => 2])
                ->visible(fn (): bool => PresentationSettingsSchema::canViewAdvanced())
                ->schema([
                    Select::make('groups')
                        ->label(__('capell-admin::form.resource_groups'))
                        ->helperText(__('capell-admin::generic.resource_groups_info'))
                        ->options(fn (): array => self::resourceGroupOptions())
                        ->multiple()
                        ->searchable()
                        ->preload(),
                    Repeater::make('loading_overrides')
                        ->label(__('capell-admin::form.resource_loading_overrides'))
                        ->helperText(__('capell-admin::generic.resource_loading_overrides_info'))
                        ->addActionLabel(__('capell-admin::button.add_resource_loading_override'))
                        ->columns(['default' => 1, 'md' => 2])
                        ->schema([
                            Select::make('group')
                                ->label(__('capell-admin::form.resource_group'))
                                ->options(fn (): array => self::resourceGroupOptions())
                                ->searchable()
                                ->required(),
                            Select::make('loading_strategy')
                                ->label(__('capell-admin::form.loading_strategy'))
                                ->options(PresentationSettingsSchema::loadingOptions())
                                ->required(),
                        ]),
                ]),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function resourceGroupOptions(): array
    {
        $options = app()->bound('capell.frontend.resource-group-options')
            ? resolve('capell.frontend.resource-group-options')
            : null;

        if (! is_callable($options)) {
            return [];
        }

        $result = $options();

        return is_array($result) ? $result : [];
    }

    /**
     * @param  array<string, mixed>|null  $state
     */
    private static function hasResourceState(?array $state): bool
    {
        foreach ($state ?? [] as $value) {
            if (is_array($value) && self::hasResourceState($value)) {
                return true;
            }

            if (! is_array($value) && filled($value)) {
                return true;
            }
        }

        return false;
    }
}
