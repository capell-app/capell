<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms\Theme;

use Capell\Admin\Enums\FooterSpacingEnum;
use Capell\Core\Support\Themes\ThemeChromeRegistry;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;
use Override;

class FooterFieldset extends Fieldset
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('capell-admin::form.footer'))
            ->schema([
                Checkbox::make('footer')
                    ->label(__('capell-admin::form.has_footer'))
                    ->default(true),
                Grid::make()
                    ->columnSpanFull()
                    ->visibleJs(<<<'JS'
                         $get('footer')
                    JS)
                    ->schema([
                        Grid::make(['@sm' => 3])
                            ->gridContainer()
                            ->columnSpanFull()
                            ->schema([
                                ColorPicker::make('footer_background_color')
                                    ->label(__('capell-admin::form.background_color'))
                                    ->autoFormat(),
                                ColorPicker::make('footer_color')
                                    ->label(__('capell-admin::form.text_color'))
                                    ->autoFormat(),
                                Select::make('footer_spacing')
                                    ->label(__('capell-admin::form.spacing'))
                                    ->options(FooterSpacingEnum::options())
                                    ->default('compact'),
                            ]),
                        Section::make(__('capell-admin::form.advanced_footer_options'))
                            ->compact()
                            ->collapsed()
                            ->columnSpanFull()
                            ->schema([
                                Grid::make(['@sm' => 3])
                                    ->gridContainer()
                                    ->schema(
                                        $this->getFooterColorSchema(
                                            color: 'footer_dark_color',
                                            backgroundColor: 'footer_dark_background_color',
                                            borderColor: 'footer_dark_border_color',
                                        ),
                                    ),
                                Grid::make(['@sm' => 2])
                                    ->gridContainer()
                                    ->schema([
                                        Checkbox::make('footer_divider')
                                            ->label(__('capell-admin::form.show_divider'))
                                            ->inline(),
                                        ColorPicker::make('footer_border_color')
                                            ->label(__('capell-admin::form.divider_color'))
                                            ->autoFormat(),
                                    ]),
                                Select::make('footer_file')
                                    ->label(__('capell-admin::form.footer_component'))
                                    ->options(fn (): array => resolve(ThemeChromeRegistry::class)->footerOptions())
                                    ->placeholder(__('capell-admin::form.default_footer_component'))
                                    ->helperText(__('capell-admin::form.footer_component_helper'))
                                    ->rule(fn (): In => Rule::in(array_keys(resolve(ThemeChromeRegistry::class)->footerOptions())))
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
    private function getFooterColorSchema(
        string $color = 'footer_color',
        string $backgroundColor = 'footer_background_color',
        string $borderColor = 'footer_border_color',
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
