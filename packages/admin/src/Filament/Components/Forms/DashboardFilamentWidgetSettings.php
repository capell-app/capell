<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms;

use Capell\Admin\Settings\AdminSettings;
use Closure;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Illuminate\Support\Str;

class DashboardFilamentWidgetSettings extends Repeater
{
    /** @var array<array{key: string, label: string, group: string, description?: string|null}>|Closure */
    private array|Closure $widgets = [];

    protected function setUp(): void
    {
        parent::setUp();

        $repeaterHydrator = $this->afterStateHydrated;

        $this
            ->label(__('capell-admin::form.dashboard_widget_grid'))
            ->hint(__('capell-admin::form.dashboard_widget_grid_helper'))
            ->columnSpanFull()
            ->defaultItems(0)
            ->addable(false)
            ->deletable(false)
            ->reorderable(false)
            ->itemLabel(fn (array $state): string => $this->widgetLabel($state))
            ->schema([
                Hidden::make('key')
                    ->dehydrated(),
                Hidden::make('label')
                    ->dehydrated(false),
                Hidden::make('group')
                    ->dehydrated(false),
                Hidden::make('description')
                    ->dehydrated(false),
                TextEntry::make('description')
                    ->hiddenLabel()
                    ->placeholder(__('capell-admin::form.dashboard_widget_description_missing'))
                    ->columnSpanFull(),
                Toggle::make('enabled')
                    ->label(__('capell-admin::form.dashboard_widget_enabled')),
                TextInput::make('order')
                    ->label(__('capell-admin::form.dashboard_widget_order'))
                    ->integer()
                    ->minValue(0),
            ])
            ->columns(2)
            ->afterStateHydrated(function (DashboardFilamentWidgetSettings $component) use ($repeaterHydrator): void {
                $component->state($component->layoutState());

                if ($repeaterHydrator instanceof Closure) {
                    $component->evaluate($repeaterHydrator, [
                        'rawState' => $component->getRawState(),
                    ]);
                }
            });
    }

    public static function getDefaultName(): ?string
    {
        return 'widget_layout';
    }

    /**
     * @param  array<array{key: string, label: string, group: string, description?: string|null}>|Closure  $widgets
     */
    public function widgets(array|Closure $widgets): static
    {
        $this->widgets = $widgets;

        return $this;
    }

    /**
     * @return list<array{key: string, label: string, group: string, description: string|null}>
     */
    public function getWidgets(): array
    {
        $widgets = $this->evaluate($this->widgets);

        if (! is_array($widgets)) {
            return [];
        }

        return array_values(collect($widgets)
            ->filter(fn (mixed $widget): bool => is_array($widget)
                && isset($widget['key'], $widget['label'], $widget['group'])
                && is_string($widget['key'])
                && is_string($widget['label'])
                && is_string($widget['group']))
            ->map(fn (mixed $widget): array => [
                'key' => $widget['key'],
                'label' => $widget['label'],
                'group' => $widget['group'],
                'description' => is_string($widget['description'] ?? null) ? $widget['description'] : null,
            ])
            ->values()
            ->all());
    }

    /**
     * @return list<array{key: string, label: string, group: string, description: string|null, enabled: bool, order: int}>
     */
    public function layoutState(): array
    {
        $settings = resolve(AdminSettings::class);

        return array_values(collect($this->getWidgets())
            ->sortBy(
                fn (array $widget, int $index): string => sprintf(
                    '%020d-%020d',
                    $this->sortOrderFor($settings, $widget['key']),
                    $index,
                ),
            )
            ->map(fn (array $widget): array => [
                'key' => $widget['key'],
                'label' => $widget['label'],
                'group' => $widget['group'],
                'description' => $widget['description'],
                'enabled' => $settings->isWidgetEnabled($widget['key']),
                'order' => $this->sortOrderFor($settings, $widget['key']),
            ])
            ->values()
            ->all());
    }

    private function sortOrderFor(AdminSettings $settings, string $settingsKey): int
    {
        if (array_key_exists($settingsKey, $settings->widget_order)) {
            return $settings->sortOrderFor($settingsKey);
        }

        return AdminSettings::defaultWidgetOrder()[$settingsKey] ?? 999;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function widgetLabel(array $state): string
    {
        $label = is_string($state['label'] ?? null) ? $state['label'] : '';
        $group = is_string($state['group'] ?? null) ? $state['group'] : '';

        if ($label !== '' && $group !== '') {
            return sprintf('%s · %s', $label, Str::headline($group));
        }

        return $label !== '' ? $label : Str::headline((string) ($state['key'] ?? ''));
    }
}
