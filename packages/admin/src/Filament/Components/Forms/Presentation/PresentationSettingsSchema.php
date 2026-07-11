<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms\Presentation;

use Capell\Admin\Enums\CapellPermission;
use Capell\Core\Data\Presentation\PresentationPresetData;
use Capell\Core\Enums\PresentationAlignment;
use Capell\Core\Enums\PresentationConnectionRequirement;
use Capell\Core\Enums\PresentationDeliveryMode;
use Capell\Core\Enums\PresentationDeviceVisibility;
use Capell\Core\Enums\PresentationLoadingStrategy;
use Capell\Core\Enums\PresentationWidthMode;
use Capell\Core\Support\Presentation\PresentationPresetRegistry;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

class PresentationSettingsSchema
{
    /**
     * @return array<int, mixed>
     */
    public static function make(string $statePath = '__capell.presentation'): array
    {
        return [
            Section::make(__('capell-admin::form.presentation_delivery'))
                ->icon('heroicon-o-sparkles')
                ->description(__('capell-admin::generic.presentation_delivery_description'))
                ->statePath($statePath)
                ->dehydrated(fn (?array $state): bool => self::hasPresentationState($state))
                ->columns(['default' => 1, 'md' => 3])
                ->schema([
                    Select::make('delivery_mode')
                        ->label(__('capell-admin::form.delivery_mode'))
                        ->options(self::deliveryOptions())
                        ->placeholder(PresentationDeliveryMode::ServerRendered->value),
                    Select::make('loading_strategy')
                        ->label(__('capell-admin::form.loading_strategy'))
                        ->options(self::loadingOptions())
                        ->placeholder(PresentationLoadingStrategy::Eager->value),
                    Select::make('lazy_policy')
                        ->label(__('capell-admin::form.lazy_policy'))
                        ->helperText(__('capell-admin::generic.lazy_policy_info'))
                        ->options(self::lazyPolicyOptions())
                        ->dehydrated(false)
                        ->afterStateHydrated(function (Select $component, Get $get): void {
                            $component->state(self::lazyPolicyFor(
                                deliveryMode: is_string($get('delivery_mode')) ? $get('delivery_mode') : null,
                                loadingStrategy: is_string($get('loading_strategy')) ? $get('loading_strategy') : null,
                            ));
                        })
                        ->afterStateUpdated(function (?string $state, Set $set): void {
                            $settings = self::presentationSettingsForLazyPolicy($state);

                            if ($settings === null) {
                                return;
                            }

                            $set('delivery_mode', $settings['delivery_mode']);
                            $set('loading_strategy', $settings['loading_strategy']);
                        }),
                    Select::make('presentation_preset')
                        ->label(__('capell-admin::form.presentation_preset'))
                        ->options(fn (): array => collect(resolve(PresentationPresetRegistry::class)->all())
                            ->reject(fn (PresentationPresetData $preset): bool => $preset->advanced && ! self::canViewAdvanced())
                            ->mapWithKeys(fn (PresentationPresetData $preset): array => [$preset->key => $preset->label])
                            ->all())
                        ->placeholder(__('capell-admin::generic.default'))
                        ->helperText(__('capell-admin::generic.presentation_preset_info')),
                    Select::make('width_mode')
                        ->label(__('capell-admin::form.presentation_width'))
                        ->options(self::widthOptions())
                        ->placeholder(__('capell-admin::generic.default'))
                        ->live(),
                    Select::make('alignment')
                        ->label(__('capell-admin::form.alignment'))
                        ->options(self::alignmentOptions())
                        ->placeholder(__('capell-admin::generic.default')),
                ]),
            Section::make(__('capell-admin::form.advanced_presentation_delivery'))
                ->icon('heroicon-o-adjustments-horizontal')
                ->description(__('capell-admin::generic.advanced_presentation_delivery_description'))
                ->statePath($statePath)
                ->dehydrated(fn (?array $state): bool => self::hasPresentationState($state))
                ->collapsed()
                ->compact()
                ->columns(['default' => 1, 'md' => 3])
                ->visible(fn (): bool => self::canViewAdvanced())
                ->schema([
                    Select::make('device_visibility')
                        ->label(__('capell-admin::form.device_visibility'))
                        ->options(self::deviceOptions())
                        ->placeholder(PresentationDeviceVisibility::All->value)
                        ->live(),
                    TextInput::make('min_viewport_width')
                        ->label(__('capell-admin::form.min_viewport_width'))
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(4096)
                        ->visible(fn (Get $get): bool => $get('device_visibility') === PresentationDeviceVisibility::CustomRange->value),
                    TextInput::make('max_viewport_width')
                        ->label(__('capell-admin::form.max_viewport_width'))
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(4096)
                        ->visible(fn (Get $get): bool => $get('device_visibility') === PresentationDeviceVisibility::CustomRange->value),
                    Select::make('connection_requirement')
                        ->label(__('capell-admin::form.connection_requirement'))
                        ->options(self::connectionOptions())
                        ->placeholder(PresentationConnectionRequirement::Any->value),
                    TextInput::make('custom_width')
                        ->label(__('capell-admin::form.custom_width'))
                        ->placeholder('72rem')
                        ->regex('/^\d+(\.\d+)?(px|rem|em|vw|%)$/')
                        ->visible(fn (Get $get): bool => $get('width_mode') === PresentationWidthMode::Custom->value),
                ]),
        ];
    }

    public static function canViewAdvanced(): bool
    {
        $user = Filament::auth()->user();

        return is_object($user)
            && method_exists($user, 'checkPermissionTo')
            && $user->checkPermissionTo(CapellPermission::ManageAdvancedPresentationSettings->name());
    }

    /**
     * @return array<string, string>
     */
    public static function deliveryOptions(): array
    {
        return [
            PresentationDeliveryMode::ServerRendered->value => __('capell-admin::generic.server_rendered'),
            PresentationDeliveryMode::LazyFragment->value => __('capell-admin::generic.lazy_fragment'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function loadingOptions(): array
    {
        return [
            PresentationLoadingStrategy::Eager->value => __('capell-admin::generic.eager'),
            PresentationLoadingStrategy::Visible->value => __('capell-admin::generic.visible'),
            PresentationLoadingStrategy::Interaction->value => __('capell-admin::generic.interaction'),
            PresentationLoadingStrategy::Idle->value => __('capell-admin::generic.idle'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function lazyPolicyOptions(): array
    {
        return [
            'server-rendered' => __('capell-admin::generic.server_rendered'),
            'visible' => __('capell-admin::generic.visible'),
            'interaction' => __('capell-admin::generic.interaction'),
            'idle' => __('capell-admin::generic.idle'),
        ];
    }

    /**
     * @return array{delivery_mode: string, loading_strategy: string}|null
     */
    public static function presentationSettingsForLazyPolicy(?string $policy): ?array
    {
        return match ($policy) {
            'server-rendered' => [
                'delivery_mode' => PresentationDeliveryMode::ServerRendered->value,
                'loading_strategy' => PresentationLoadingStrategy::Eager->value,
            ],
            'visible' => [
                'delivery_mode' => PresentationDeliveryMode::LazyFragment->value,
                'loading_strategy' => PresentationLoadingStrategy::Visible->value,
            ],
            'interaction' => [
                'delivery_mode' => PresentationDeliveryMode::LazyFragment->value,
                'loading_strategy' => PresentationLoadingStrategy::Interaction->value,
            ],
            'idle' => [
                'delivery_mode' => PresentationDeliveryMode::LazyFragment->value,
                'loading_strategy' => PresentationLoadingStrategy::Idle->value,
            ],
            default => null,
        };
    }

    public static function lazyPolicyFor(?string $deliveryMode, ?string $loadingStrategy): string
    {
        if ($deliveryMode !== PresentationDeliveryMode::LazyFragment->value) {
            return 'server-rendered';
        }

        return match ($loadingStrategy) {
            PresentationLoadingStrategy::Visible->value => 'visible',
            PresentationLoadingStrategy::Interaction->value => 'interaction',
            PresentationLoadingStrategy::Idle->value => 'idle',
            default => 'server-rendered',
        };
    }

    /**
     * @param  array<string, mixed>|null  $state
     */
    private static function hasPresentationState(?array $state): bool
    {
        foreach ($state ?? [] as $value) {
            if (is_array($value) && self::hasPresentationState($value)) {
                return true;
            }

            if (! is_array($value) && filled($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string>
     */
    private static function deviceOptions(): array
    {
        return [
            PresentationDeviceVisibility::All->value => __('capell-admin::generic.all_devices'),
            PresentationDeviceVisibility::MobileOnly->value => __('capell-admin::generic.mobile_only'),
            PresentationDeviceVisibility::DesktopOnly->value => __('capell-admin::generic.desktop_only'),
            PresentationDeviceVisibility::CustomRange->value => __('capell-admin::generic.custom_range'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function connectionOptions(): array
    {
        return [
            PresentationConnectionRequirement::Any->value => __('capell-admin::generic.any_connection'),
            PresentationConnectionRequirement::FastOnly->value => __('capell-admin::generic.fast_connection_only'),
            PresentationConnectionRequirement::HideOnSaveData->value => __('capell-admin::generic.hide_on_save_data'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function widthOptions(): array
    {
        return [
            PresentationWidthMode::Inherit->value => __('capell-admin::generic.inherit'),
            PresentationWidthMode::Full->value => __('capell-admin::generic.full_width'),
            PresentationWidthMode::Container->value => __('capell-admin::generic.container'),
            PresentationWidthMode::Custom->value => __('capell-admin::generic.custom'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function alignmentOptions(): array
    {
        return [
            PresentationAlignment::Stretch->value => __('capell-admin::generic.stretch'),
            PresentationAlignment::Left->value => __('capell-admin::generic.left'),
            PresentationAlignment::Center->value => __('capell-admin::generic.center'),
            PresentationAlignment::Right->value => __('capell-admin::generic.right'),
        ];
    }
}
