<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms\Interactions;

use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Components\Forms\Presentation\PresentationSettingsSchema;
use Capell\Core\Enums\InteractionBehavior;
use Capell\Core\Enums\InteractionTargetType;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;

class InteractionSettingsSchema
{
    /**
     * @return array<int, mixed>
     */
    public static function make(string $statePath = '__capell.interactions'): array
    {
        return [
            Section::make(__('capell-admin::form.interactions'))
                ->icon('heroicon-o-bolt')
                ->description(__('capell-admin::generic.interactions_description'))
                ->collapsed()
                ->compact()
                ->schema([
                    Repeater::make($statePath)
                        ->label(__('capell-admin::form.interactions'))
                        ->addActionLabel(__('capell-admin::button.add_interaction'))
                        ->defaultItems(0)
                        ->collapsed()
                        ->cloneable()
                        ->itemLabel(fn (array $state): ?string => self::itemLabel($state))
                        ->schema(self::triggerSchema()),
                ]),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    private static function triggerSchema(): array
    {
        return [
            Grid::make(['md' => 3])
                ->schema([
                    TextInput::make('label')
                        ->label(__('capell-admin::form.label'))
                        ->required(),
                    TextInput::make('icon')
                        ->label(__('capell-admin::form.icon'))
                        ->placeholder('heroicon-o-play'),
                    Select::make('style')
                        ->label(__('capell-admin::form.style'))
                        ->options([
                            'primary' => __('capell-admin::generic.primary'),
                            'secondary' => __('capell-admin::generic.secondary'),
                            'subtle' => __('capell-admin::generic.subtle'),
                        ])
                        ->default('primary'),
                ]),
            Grid::make(['md' => 3])
                ->schema([
                    Select::make('target_type')
                        ->label(__('capell-admin::form.interaction_target'))
                        ->options(self::targetOptions())
                        ->default(InteractionTargetType::Widget->value)
                        ->live()
                        ->required(),
                    Select::make('behavior')
                        ->label(__('capell-admin::form.interaction_behavior'))
                        ->options(self::behaviorOptions())
                        ->default(InteractionBehavior::Modal->value)
                        ->required()
                        ->visible(fn (Get $get): bool => $get('target_type') !== InteractionTargetType::Url->value),
                    Select::make('modal_size')
                        ->label(__('capell-admin::form.modal_size'))
                        ->options([
                            'sm' => __('capell-admin::generic.small'),
                            'md' => __('capell-admin::generic.medium'),
                            'lg' => __('capell-admin::generic.large'),
                            'xl' => __('capell-admin::generic.extra_large'),
                            'screen' => __('capell-admin::generic.full_screen'),
                        ])
                        ->placeholder(__('capell-admin::generic.default'))
                        ->visible(fn (Get $get): bool => in_array($get('behavior'), [InteractionBehavior::Modal->value, InteractionBehavior::SlideOver->value], true)),
                ]),
            Builder::make('target_widget')
                ->label(__('capell-admin::form.target_widget'))
                ->blocks(CapellAdmin::getFilamentWidgets())
                ->maxItems(1)
                ->columnSpanFull()
                ->visible(fn (Get $get): bool => $get('target_type') === InteractionTargetType::Widget->value),
            TextInput::make('url')
                ->label(__('capell-admin::form.url'))
                ->url()
                ->visible(fn (Get $get): bool => $get('target_type') === InteractionTargetType::Url->value),
            TextInput::make('public_action_key')
                ->label(__('capell-admin::form.public_action_key'))
                ->visible(fn (Get $get): bool => $get('target_type') === InteractionTargetType::PublicAction->value),
            ...PresentationSettingsSchema::make('presentation'),
            Grid::make(['md' => 3])
                ->schema([
                    TextInput::make('aria_label')
                        ->label(__('capell-admin::form.aria_label')),
                    TextInput::make('analytics_key')
                        ->label(__('capell-admin::form.analytics_key')),
                    TextInput::make('fallback_url')
                        ->label(__('capell-admin::form.fallback_url')),
                ]),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function targetOptions(): array
    {
        return [
            InteractionTargetType::Widget->value => __('capell-admin::generic.widget'),
            InteractionTargetType::Url->value => __('capell-admin::generic.url'),
            InteractionTargetType::PublicAction->value => __('capell-admin::generic.public_action'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function behaviorOptions(): array
    {
        return [
            InteractionBehavior::Modal->value => __('capell-admin::generic.modal'),
            InteractionBehavior::SlideOver->value => __('capell-admin::generic.slide_over'),
            InteractionBehavior::InlineReveal->value => __('capell-admin::generic.inline_reveal'),
            InteractionBehavior::ReplaceRegion->value => __('capell-admin::generic.replace_region'),
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private static function itemLabel(array $state): ?string
    {
        $label = $state['label'] ?? null;

        return is_string($label) && $label !== '' ? $label : null;
    }
}
