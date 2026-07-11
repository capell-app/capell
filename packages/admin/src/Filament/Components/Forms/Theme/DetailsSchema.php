<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms\Theme;

use Capell\Admin\Filament\Components\Forms\KeyTextInput;
use Capell\Admin\Filament\Components\Forms\NameKeyGroup;
use Capell\Core\Models\Theme;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

class DetailsSchema
{
    /**
     * @return array<int, mixed>
     */
    public static function make(Schema $schema): array
    {
        return [
            Section::make(__('capell-admin::form.theme_identity'))
                ->description(__('capell-admin::theme-editor.descriptions.identity'))
                ->columns()
                ->columnSpanFull()
                ->schema([
                    NameKeyGroup::make(
                        modifyKey: fn (KeyTextInput $component): KeyTextInput => $component->unique(
                            table: Theme::class,
                            ignoreRecord: $schema->getOperation() !== 'replicate',
                            modifyRuleUsing: fn (Unique $rule) => $rule->withoutTrashed(),
                        ),
                    ),
                    TextInput::make('order')
                        ->label(__('capell-admin::form.order'))
                        ->helperText(__('capell-admin::theme-editor.help.order'))
                        ->required()
                        ->numeric()
                        ->default(fn (): int => Theme::query()->enabled()->max('order') + 1)
                        ->minValue(0)
                        ->step(1),
                ]),
        ];
    }
}
