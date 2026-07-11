<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms\Theme;

use Capell\Admin\Filament\Components\Forms\FontStyleSelect;
use Capell\Admin\Filament\Components\Forms\FontWeightSelect;
use Capell\Admin\Support\Themes\ThemeFontUploadPolicy;
use Capell\Core\Enums\FontStyleEnum;
use Capell\Core\Enums\FontTypeEnum;
use Capell\Core\Enums\FontWeightEnum;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class FontsSchema
{
    /**
     * @return array<int, mixed>
     */
    public static function make(): array
    {
        return [
            static::fontsRepeater(),
            Select::make('font_family')
                ->label(__('capell-admin::form.font_family'))
                ->requiredWith('fonts')
                ->options(static::fontNames(...)),
            Select::make('font_heading_family')
                ->label(__('capell-admin::form.font_heading_family'))
                ->options(static::fontNames(...)),
        ];
    }

    protected static function fontsRepeater(): Repeater
    {
        return Repeater::make('fonts')
            ->label(__('capell-admin::form.fonts'))
            ->hiddenLabel()
            ->columnSpanFull()
            ->defaultItems(0)
            ->minItems(0)
            ->collapsible()
            ->reorderableWithDragAndDrop(false)
            ->reactive()
            ->itemLabel(function (?array $state): ?string {
                if ($state === null || $state === []) {
                    return null;
                }

                $label = $state['name'];

                if (isset($state['style']) && filled($state['style']) && $state['style'] !== FontStyleEnum::Normal) {
                    $label .= ' - ' . $state['style'];
                }

                if (isset($state['weight']) && filled($state['weight']) && $state['weight'] !== FontWeightEnum::Normal) {
                    $label .= ' - ' . $state['weight'];
                }

                return $label;
            })
            ->afterStateUpdated(function (?array $state, Set $set): void {
                if ($state === null || $state === []) {
                    $set('font_family', null);
                    $set('font_heading_family', null);
                }
            })
            ->schema([
                Grid::make()
                    ->gridContainer()
                    ->columns(['@sm' => 3])
                    ->schema([
                        FontTypeToggleButtons::make(),
                        Group::make()
                            ->columnSpan(['@sm' => 2])
                            ->visibleJs(function (): string {
                                $type = FontTypeEnum::Url->value;

                                return <<<JS
                                 \$get('type') === '{$type}'
                                JS;
                            })
                            ->schema(static::fontUrlSchema()),
                        Group::make()
                            ->columnSpan(['@sm' => 2])
                            ->visibleJs(function (): string {
                                $type = FontTypeEnum::Local->value;

                                return <<<JS
                                 \$get('type') === '{$type}'
                                JS;
                            })
                            ->schema(static::localFontSchema()),
                    ]),
                Grid::make()
                    ->gridContainer()
                    ->columns(['@sm' => 3])
                    ->visibleJs(<<<'JS'
                         $get('type')
                    JS)
                    ->schema(self::fontSchema()),
            ]);
    }

    /**
     * @return array<int, mixed>
     */
    protected static function fontUrlSchema(): array
    {
        return [
            TextInput::make('url')
                ->label(__('capell-admin::form.url'))
                ->url()
                ->requiredIf('type', FontTypeEnum::Url->value)
                ->placeholder('https://fonts.googleapis.com/css2?family=Roboto:wght@400&display=swap')
                ->helperText(__('capell-admin::generic.font_url_info'))
                ->validationAttribute(__('capell-admin::form.url'))
                ->lazy()
                ->afterStateUpdated(function (?string $state, Set $set, Get $get): void {
                    if (in_array($state, [null, '', '0'], true)) {
                        return;
                    }

                    $name = $get('name');
                    if ($name !== null && $name !== '') {
                        return;
                    }

                    $query = parse_url($state, PHP_URL_QUERY);
                    if (! is_string($query)) {
                        return;
                    }

                    $fontFamily = preg_replace('/^.*?family=([^:;]+).*$/', '$1', $query);

                    if (! is_string($fontFamily)) {
                        return;
                    }

                    $fontFamily = preg_replace('/&.*$/', '', $fontFamily);

                    if (! is_string($fontFamily)) {
                        return;
                    }

                    $set('name', $fontFamily);
                }),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    protected static function localFontSchema(): array
    {
        return [
            FileUpload::make('files')
                ->label(__('capell-admin::form.font_files'))
                ->directory('theme/fonts')
                ->requiredIf('type', FontTypeEnum::Local->value)
                ->minFiles(fn (FileUpload $component): int => $component->isRequired() ? 1 : 0)
                ->acceptedFileTypes(ThemeFontUploadPolicy::acceptedFileTypes())
                ->rules(ThemeFontUploadPolicy::validationRules())
                ->multiple()
                ->getUploadedFileNameForStorageUsing(
                    fn (TemporaryUploadedFile $file): string => ThemeFontUploadPolicy::storageFileName($file),
                ),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    protected static function fontSchema(): array
    {
        return [
            TextInput::make('name')
                ->label(__('capell-admin::form.font_name'))
                ->required(),
            FontWeightSelect::make()
                ->visibleJs(function (): string {
                    $type = FontTypeEnum::Local->value;

                    return <<<JS
                     \$get('type') === '{$type}'
                    JS;
                }),
            FontStyleSelect::make()
                ->visibleJs(function (): string {
                    $type = FontTypeEnum::Local->value;

                    return <<<JS
                     \$get('type') === '{$type}'
                    JS;
                }),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected static function fontNames(Get $get): array
    {
        $fonts = $get('fonts');
        $fontNames = [];

        foreach (is_array($fonts) ? $fonts : [] as $font) {
            if (! is_array($font)) {
                continue;
            }

            if (! isset($font['name'])) {
                continue;
            }

            if ($font['name'] === '') {
                continue;
            }

            $fontNames[(string) $font['name']] = (string) $font['name'];
        }

        ksort($fontNames);

        return $fontNames;
    }
}
