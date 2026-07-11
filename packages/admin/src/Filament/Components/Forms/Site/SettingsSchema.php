<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms\Site;

use Capell\Admin\Data\Configurators\ConfiguratorContextData;
use Capell\Admin\Enums\ConfiguratorTypeEnum;
use Capell\Admin\Filament\Components\Forms\DefaultToggle;
use Capell\Admin\Filament\Components\Forms\NameInput;
use Capell\Admin\Filament\Components\Forms\StatusToggle;
use Capell\Admin\Filament\Components\Forms\ThemeSelect;
use Capell\Admin\Support\Configurators\ConfiguratorResolver;
use Capell\Core\Models\Site;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

class SettingsSchema
{
    /**
     * @param  array<int, mixed>  $components
     * @return array<int, mixed>
     */
    public static function make(Schema $schema, array $components = [], ?ConfiguratorContextData $context = null): array
    {
        return [
            NameInput::make('name')
                ->default(fn (string $operation): string => config('app.name'))
                ->unique(
                    ignoreRecord: $schema->getOperation() !== 'replicate',
                    modifyRuleUsing: fn (Unique $rule) => $rule->withoutTrashed(),
                ),
            Hidden::make('blueprint_id')
                ->default(fn (): int|string|null => $schema->isCreating() ? static::resolveTypeId($context) : null)
                ->dehydrated($schema->isCreating()),
            ThemeSelect::make('theme_id')
                ->required()
                ->when(
                    $schema->isCreating(),
                    fn (ThemeSelect $component): ThemeSelect => $component->withCreateForm(),
                    fn (ThemeSelect $component): ThemeSelect => $component->withEditForm(),
                ),
            ...$components,
            TextInput::make('order')
                ->label(__('capell-admin::form.order'))
                ->required()
                ->numeric()
                ->default(fn (): int => Site::query()->enabled()->max('order') + 1)
                ->minValue(0)
                ->step(1),
            DefaultToggle::make('default'),
            StatusToggle::make('status'),
        ];
    }

    protected static function resolveTypeId(?ConfiguratorContextData $context = null): int|string|null
    {
        $resolver = resolve(ConfiguratorResolver::class);

        if (filled($context?->typeKey)) {
            return $resolver->resolveTypeByKey($context->typeKey, ConfiguratorTypeEnum::Site)->getKey();
        }

        return $resolver->resolveDefaultType(ConfiguratorTypeEnum::Site)->getKey();
    }
}
