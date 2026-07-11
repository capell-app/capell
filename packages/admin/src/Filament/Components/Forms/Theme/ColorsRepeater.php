<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms\Theme;

use Capell\Core\Enums\DefaultColorEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Support\Icons\Heroicon;

class ColorsRepeater extends Repeater
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('capell-admin::form.colors'))
            ->orderColumn()
            ->table([
                TableColumn::make('name'),
                TableColumn::make('color'),
            ])
            ->default(fn (): array => DefaultColorEnum::getValues())
            ->columnSpanFull()
            ->columns()
            ->itemLabel(fn (array $state): ?string => $state['name']->value ?? null)
            ->afterStateHydrated(function (Repeater $component, ?array $state): void {
                $component->state(
                    collect($state)
                        ->map(function (null|string|array $color, string $name): array {
                            if (is_array($color)) {
                                $name = $color['name'];
                                $color = $color['color'];
                            }

                            return ['name' => $name, 'color' => $color];
                        })
                        ->all(),
                );
            })
            ->mutateDehydratedStateUsing(
                fn (?array $state): array => collect($state)
                    ->mapWithKeys(
                        fn (array $item): array => [$item['name']->value => $item['color']],
                    )
                    ->toArray(),
            )
            ->schema([
                Select::make('name')
                    ->hiddenLabel()
                    ->options(DefaultColorEnum::class)
                    ->required()
                    ->disableOptionsWhenSelectedInSiblingRepeaterItems()
                    ->reactive(),

                ColorPicker::make('color')
                    ->hiddenLabel()
                    ->autoFormat(),
            ])
            ->hintAction(
                Action::make('defaultColors')
                    ->label(__('capell-admin::button.add_missing_colours'))
                    ->icon(Heroicon::Plus)
                    ->visible(function (?array $state): bool {
                        $defaultColors = collect(DefaultColorEnum::getValues())->pluck('name')->all();
                        $currentNames = collect($state ?? [])->pluck('name')->all();
                        $missing = array_diff($defaultColors, $currentNames);

                        return $missing !== [];
                    })
                    ->action(function (?array $state, Repeater $component): void {
                        $defaultColors = collect(DefaultColorEnum::getValues());
                        $currentState = $state ?? [];
                        $currentNames = collect($currentState)->pluck('name')->all();
                        $missing = $defaultColors->reject(fn (array $color): bool => in_array($color['name'], $currentNames, true))->values();

                        if ($missing->isEmpty()) {
                            return;
                        }

                        $newState = collect($currentState)->values()->all();
                        foreach ($missing as $color) {
                            $newState[] = $color;
                        }

                        $component->state($newState);
                    }),
            );
    }

    public static function getDefaultName(): ?string
    {
        return 'colors';
    }
}
