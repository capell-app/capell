<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Settings;

use Capell\Admin\Enums\ThemeStudioCardDensityEnum;
use Capell\Admin\Enums\ThemeStudioHeadingScaleEnum;
use Capell\Admin\Enums\ThemeStudioOverlayTreatmentEnum;
use Capell\Admin\Enums\ThemeStudioRadiusEnum;
use Capell\Admin\Filament\Contracts\HasSchema;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class ThemeStudioSettingsSchema implements HasSchema
{
    public static function make(Schema $schema): array
    {
        return [
            Section::make(__('capell-admin::form.theme_studio_brand_colours'))
                ->columnSpanFull()
                ->description(__('capell-admin::generic.theme_studio_brand_colours_info'))
                ->schema([
                    Grid::make(3)
                        ->schema([
                            ColorPicker::make('brandProfile.primaryColor')
                                ->label(__('capell-admin::form.theme_studio_primary_color'))
                                ->helperText(__('capell-admin::form.theme_studio_primary_color_helper'))
                                ->required(),
                            ColorPicker::make('brandProfile.accentColor')
                                ->label(__('capell-admin::form.theme_studio_accent_color'))
                                ->helperText(__('capell-admin::form.theme_studio_accent_color_helper'))
                                ->required(),
                            ColorPicker::make('brandProfile.neutralColor')
                                ->label(__('capell-admin::form.theme_studio_neutral_color'))
                                ->helperText(__('capell-admin::form.theme_studio_neutral_color_helper'))
                                ->required(),
                            ColorPicker::make('brandProfile.surfaceColor')
                                ->label(__('capell-admin::form.theme_studio_surface_color'))
                                ->helperText(__('capell-admin::form.theme_studio_surface_color_helper'))
                                ->required(),
                            ColorPicker::make('brandProfile.foregroundColor')
                                ->label(__('capell-admin::form.theme_studio_foreground_color'))
                                ->helperText(__('capell-admin::form.theme_studio_foreground_color_helper'))
                                ->required(),
                        ]),
                ]),
            Section::make(__('capell-admin::form.theme_studio_presentation_defaults'))
                ->columnSpanFull()
                ->description(__('capell-admin::generic.theme_studio_presentation_defaults_info'))
                ->schema([
                    Grid::make(2)
                        ->schema([
                            ToggleButtons::make('brandProfile.radius')
                                ->label(__('capell-admin::form.theme_studio_radius'))
                                ->helperText(__('capell-admin::form.theme_studio_radius_helper'))
                                ->enum(ThemeStudioRadiusEnum::class)
                                ->inline()
                                ->required(),
                            ToggleButtons::make('brandProfile.headingScale')
                                ->label(__('capell-admin::form.theme_studio_heading_scale'))
                                ->helperText(__('capell-admin::form.theme_studio_heading_scale_helper'))
                                ->enum(ThemeStudioHeadingScaleEnum::class)
                                ->inline()
                                ->required(),
                            ToggleButtons::make('brandProfile.cardDensity')
                                ->label(__('capell-admin::form.theme_studio_card_density'))
                                ->helperText(__('capell-admin::form.theme_studio_card_density_helper'))
                                ->enum(ThemeStudioCardDensityEnum::class)
                                ->inline()
                                ->required(),
                            ToggleButtons::make('brandProfile.overlayTreatment')
                                ->label(__('capell-admin::form.theme_studio_overlay_treatment'))
                                ->helperText(__('capell-admin::form.theme_studio_overlay_treatment_helper'))
                                ->enum(ThemeStudioOverlayTreatmentEnum::class)
                                ->inline()
                                ->required(),
                        ]),
                ]),
        ];
    }
}
