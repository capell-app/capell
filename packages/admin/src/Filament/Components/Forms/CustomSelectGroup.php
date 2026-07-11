<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms;

use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\FusedGroup;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Contracts\HasLabel as LabelInterface;
use Illuminate\Contracts\Support\Arrayable;
use UnitEnum;

class CustomSelectGroup
{
    /**
     * @param  array<int|string, mixed>|Arrayable<int|string, mixed>|string|callable  $options
     */
    public static function make(
        string $name,
        array|Arrayable|string|callable $options,
        ?string $placeholder = null,
        ?bool $required = null,
        ?Closure $modifySelectUsing = null,
        ?Closure $modifyCustomInputUsing = null,
    ): FusedGroup {
        $select = Select::make($name)
            ->mutateDehydratedStateUsing(
                fn (?string $state, Get $get): mixed => $state === 'custom' ? $get($name . '_custom') : $state,
            )
            ->disabled(fn (?string $state): bool => $state === 'custom')
            ->placeholder($placeholder)
            ->options(function (Select $component, ?string $state) use ($options): array {
                if (is_callable($options)) {
                    $options = $component->evaluate($options);
                }

                // If options is a class-string of an enum, reduce cases to key=>label
                if (is_string($options) && enum_exists($enum = $options)) {
                    /** @var class-string<UnitEnum> $enum */
                    if (is_a($enum, LabelInterface::class, allow_string: true)) {
                        /** @var class-string<UnitEnum&LabelInterface> $enum */
                        $options = array_reduce($enum::cases(), function (array $carry, LabelInterface&UnitEnum $case): array {
                            $key = $case instanceof BackedEnum ? $case->value : $case->name;
                            $label = $case->getLabel();

                            $carry[$key] = $label;

                            return $carry;
                        }, []);
                    } else {
                        $options = array_reduce($enum::cases(), function (array $carry, UnitEnum $case): array {
                            $key = $case instanceof BackedEnum ? $case->value : $case->name;

                            $carry[$key] = $case->name;

                            return $carry;
                        }, []);
                    }
                }

                if ($options instanceof Arrayable) {
                    $options = $options->toArray();
                }

                if ($state !== null && $state !== '' && ! isset($options[$state])) {
                    $options[$state] = $state;
                }

                $options['custom'] = __('capell-admin::form.option_custom');

                return $options;
            });

        if ($required !== null) {
            $select->required($required);
        }

        if ($modifySelectUsing instanceof Closure) {
            $select = $modifySelectUsing($select);
        }

        $customInput = TextInput::make($name . '_custom')
            ->label(__('capell-admin::form.custom_group'))
            ->hiddenLabel()
            ->placeholder(__('capell-admin::button.add_custom'))
            ->dehydrated(false)
            ->visibleJs(<<<JS
                \$get('{$name}') === 'custom'
            JS)
            ->suffixAction(
                Action::make('cancel')
                    ->label(__('capell-admin::button.cancel'))
                    ->color('secondary')
                    ->hiddenLabel()
                    ->icon('heroicon-c-x-mark')
                    ->iconButton()
                    ->size('sm')
                    ->action(function (Set $set) use ($name): void {
                        $set($name, null);
                    }),
            );

        if ($modifyCustomInputUsing instanceof Closure) {
            $customInput = $modifyCustomInputUsing($customInput);
        }

        return FusedGroup::make()
            ->schema([
                $select,
                $customInput,
            ]);
    }
}
