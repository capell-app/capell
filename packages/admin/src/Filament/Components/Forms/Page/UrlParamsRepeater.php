<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms\Page;

use Capell\Core\Enums\UrlParamTypeEnum;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;

class UrlParamsRepeater extends Repeater
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('capell-admin::form.url_params'))
            ->addActionLabel(__('capell-admin::button.add_url_param'))
            ->minItems(0)
            ->defaultItems(0)
            ->table([
                TableColumn::make(__('capell-admin::form.key'))
                    ->hiddenHeaderLabel(),
                TableColumn::make(__('capell-admin::form.value'))
                    ->hiddenHeaderLabel(),
            ])
            ->afterStateHydrated(function (Repeater $component, ?array $state): void {
                $component->state(
                    collect($state)
                        ->mapWithKeys(fn (array $item): array => [$item['key'] => $item['value']])
                        ->all(),
                );
            })
            ->mutateDehydratedStateUsing(
                fn (array $state): array => collect($state)
                    ->map(fn (string $value, string $name): array => ['key' => $name, 'value' => $value])
                    ->all(),
            )
            ->schema([
                TextInput::make('key')
                    ->label(__('capell-admin::form.key'))
                    ->required()
                    ->distinct()
                    ->helperText(fn (mixed $state): ?string => is_string($state) && $state !== '' ? null : $this->translationString('capell-admin::generic.url_param_key_info')),

                Select::make('value')
                    ->label(__('capell-admin::form.value'))
                    ->options(UrlParamTypeEnum::class)
                    ->required()
                    ->helperText(fn (mixed $state): ?string => is_string($state) && $state !== '' ? null : $this->translationString('capell-admin::generic.url_param_value_info')),
            ]);
    }

    private function translationString(string $key): string
    {
        $value = __($key);

        return is_string($value) ? $value : $key;
    }
}
