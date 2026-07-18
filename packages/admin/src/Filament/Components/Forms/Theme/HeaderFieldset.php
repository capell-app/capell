<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms\Theme;

use Capell\Admin\Enums\HeaderShadowEnum;
use Capell\Core\Enums\HeaderPositionEnum;
use Capell\Core\Enums\MenuAlignmentEnum;
use Capell\Core\Support\Themes\ThemeChromeRegistry;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;
use Override;

class HeaderFieldset extends Fieldset
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('capell-admin::form.header'))
            ->schema([
                Checkbox::make('header')
                    ->label(__('capell-admin::form.has_header'))
                    ->default(true),
                Grid::make()
                    ->columnSpanFull()
                    ->visibleJs(<<<'JS'
                         $get('header')
                    JS)
                    ->schema([
                        Grid::make(['@sm' => 2])
                            ->gridContainer()
                            ->columnSpanFull()
                            ->schema([
                                ColorPicker::make('header_background_color')
                                    ->label(__('capell-admin::form.background_color'))
                                    ->autoFormat(),
                                ColorPicker::make('header_color')
                                    ->label(__('capell-admin::form.text_color'))
                                    ->autoFormat(),
                            ]),
                        Section::make(__('capell-admin::form.header_behaviour'))
                            ->compact()
                            ->columnSpanFull()
                            ->schema([
                                Grid::make(['@sm' => 3])
                                    ->gridContainer()
                                    ->schema([
                                        Select::make('header_position')
                                            ->label(__('capell-admin::form.header_position'))
                                            ->options(HeaderPositionEnum::class)
                                            ->default(HeaderPositionEnum::Static_),
                                        Select::make('header_menu_alignment')
                                            ->label(__('capell-admin::form.header_menu_alignment'))
                                            ->options(MenuAlignmentEnum::class),
                                        Select::make('header_shadow')
                                            ->label(__('capell-admin::form.shadow'))
                                            ->options(HeaderShadowEnum::class)
                                            ->default('none'),
                                        Checkbox::make('header_over_hero')
                                            ->label(__('capell-admin::form.header_over_hero'))
                                            ->helperText(__('capell-admin::form.header_over_hero_helper'))
                                            ->default(false),
                                    ]),
                            ]),
                        Section::make(__('capell-admin::form.advanced_header_options'))
                            ->compact()
                            ->collapsed()
                            ->columnSpanFull()
                            ->schema([
                                Grid::make(['@sm' => 3])
                                    ->gridContainer()
                                    ->schema(
                                        $this->getHeaderColorSchema(
                                            color: 'header_dark_color',
                                            backgroundColor: 'header_dark_background_color',
                                            borderColor: 'header_dark_border_color',
                                        ),
                                    ),
                                Grid::make(['@sm' => 3])
                                    ->gridContainer()
                                    ->schema([
                                        Checkbox::make('header_divider')
                                            ->label(__('capell-admin::form.show_divider'))
                                            ->inline(),
                                        ColorPicker::make('header_border_color')
                                            ->label(__('capell-admin::form.divider_color'))
                                            ->autoFormat(),
                                        TextInput::make('header_height')
                                            ->label(__('capell-admin::form.height'))
                                            ->placeholder('4rem'),
                                    ]),
                                Select::make('header_file')
                                    ->label(__('capell-admin::form.header_component'))
                                    ->options(fn (): array => resolve(ThemeChromeRegistry::class)->headerOptions())
                                    ->placeholder(__('capell-admin::form.default_header_component'))
                                    ->helperText(__('capell-admin::form.header_component_helper'))
                                    ->rule(fn (): In => Rule::in(array_keys(resolve(ThemeChromeRegistry::class)->headerOptions())))
                                    ->nullable()
                                    ->searchable()
                                    ->columnSpanFull(),
                            ]),
                    ]),
            ]);
    }

    /**
     * @return array<int, mixed>
     */
    private function getHeaderColorSchema(
        string $color = 'header_color',
        string $backgroundColor = 'header_background_color',
        string $borderColor = 'header_border_color',
    ): array {
        return [
            ColorPicker::make($backgroundColor)
                ->label(__('capell-admin::form.background_color'))
                ->autoFormat(),
            ColorPicker::make($borderColor)
                ->label(__('capell-admin::form.border_color'))
                ->autoFormat(),
            ColorPicker::make($color)
                ->label(__('capell-admin::form.text_color'))
                ->autoFormat(),
        ];
    }
}
