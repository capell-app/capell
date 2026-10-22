<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms;

use Filament\Forms\Components\DateTimePicker;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\Date;
use Override;

class PublishDatesGrid extends Grid
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->gridContainer()
            ->columns(['lg' => null, '@lg' => 2])
            ->schema([
                static::getVisibleFromField(),
                static::getVisibleUntilField(),
            ]);
    }

    public static function getVisibleFromField(): DateTimePicker
    {
        return DateTimePicker::make('visible_from')
            ->label(__('capell-admin::form.publish_from'))
            ->validationAttribute(__('capell-admin::form.publish_from'))
            ->helperText(function (?string $state, string $operation): ?string {
                if ($operation === 'create') {
                    return __('capell-admin::generic.visible_from_create_info');
                }

                if ($operation === 'createOption') {
                    return __('capell-admin::generic.visible_from_create_option_info');
                }

                if (in_array($state, [null, '', '0'], true)) {
                    return __('capell-admin::generic.visible_from_info');
                }

                $date = Date::parse($state);

                if ($date->isFuture()) {
                    return __('capell-admin::generic.visible_from_future', ['date' => $date->diffForHumans()]);
                }

                return null;
            })
            ->time()
            ->seconds(false)
            ->nullable()
            ->rules(function (Get $get, string $operation): array {
                $rules = ['nullable', 'date'];

                if (in_array($operation, ['create', 'createOption'], true)) {
                    $rules[] = 'before_or_equal:now';
                }

                $publishTo = $get('visible_until');
                if ($publishTo !== null && $publishTo !== '') {
                    $rules[] = 'before_or_equal:' . Date::parse($publishTo)->toDateTimeString();
                }

                return $rules;
            });
    }

    public static function getVisibleUntilField(): DateTimePicker
    {
        return DateTimePicker::make('visible_until')
            ->label(__('capell-admin::form.visible_until'))
            ->helperText(function (?string $state): ?string {
                if (in_array($state, [null, '', '0'], true)) {
                    return __('capell-admin::generic.visible_until_info');
                }

                $date = Date::parse($state);

                if ($date->isFuture()) {
                    return __('capell-admin::generic.visible_until_future', ['date' => $date->diffForHumans()]);
                }

                return null;
            })
            ->time()
            ->seconds(false)
            ->nullable()
            ->rules(function (Get $get): array {
                $rules = ['nullable', 'date'];
                $publishFrom = $get('visible_from');

                if ($publishFrom !== null && $publishFrom !== '') {
                    $rules[] = 'after_or_equal:' . Date::parse($publishFrom)->toDateTimeString();
                }

                return $rules;
            });
    }
}
