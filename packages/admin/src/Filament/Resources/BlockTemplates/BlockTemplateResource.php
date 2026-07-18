<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\BlockTemplates;

use BackedEnum;
use Capell\Admin\Filament\Resources\BlockTemplates\Pages\ManageBlockTemplates;
use Capell\Core\Models\BlockTemplate;
use Capell\Core\Support\Json\JsonCodec;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CodeEditor;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use Override;

final class BlockTemplateResource extends Resource
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquaresPlus;

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::SquaresPlus;

    protected static ?int $navigationSort = 45;

    /**
     * @return class-string<BlockTemplate>
     */
    #[Override]
    public static function getModel(): string
    {
        return BlockTemplate::class;
    }

    #[Override]
    public static function getNavigationLabel(): string
    {
        return (string) __('capell-admin::navigation.block_templates');
    }

    #[Override]
    public static function getNavigationGroup(): string
    {
        return (string) __('capell-admin::navigation.group_content');
    }

    #[Override]
    public static function getModelLabel(): string
    {
        return (string) __('capell-admin::form.block_template');
    }

    #[Override]
    public static function getPluralModelLabel(): string
    {
        return (string) __('capell-admin::navigation.block_templates');
    }

    #[Override]
    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('capell-admin::form.block_template_details'))
                    ->columns()
                    ->schema([
                        TextInput::make('name')
                            ->label(__('capell-admin::form.name'))
                            ->required()
                            ->maxLength(255),
                        TextInput::make('key')
                            ->label(__('capell-admin::form.key'))
                            ->required()
                            ->maxLength(255)
                            ->unique(
                                table: BlockTemplate::class,
                                ignoreRecord: true,
                            ),
                        Textarea::make('description')
                            ->label(__('capell-admin::form.description'))
                            ->columnSpanFull(),
                        Toggle::make('enabled')
                            ->label(__('capell-admin::form.enabled'))
                            ->default(true),
                    ]),
                CodeEditor::make('blocks')
                    ->label(__('capell-admin::form.block_template_blocks'))
                    ->required()
                    ->formatStateUsing(fn (mixed $state): string => self::formatBlocksState($state))
                    ->dehydrateStateUsing(fn (mixed $state): array => self::decodeBlocksState($state))
                    ->rules(['json'])
                    ->columnSpanFull(),
            ]);
    }

    #[Override]
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('capell-admin::table.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('key')
                    ->label(__('capell-admin::table.key'))
                    ->searchable()
                    ->copyable(),
                IconColumn::make('enabled')
                    ->label(__('capell-admin::form.enabled'))
                    ->boolean()
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label(__('capell-admin::table.updated_at'))
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->defaultSort('name');
    }

    #[Override]
    public static function getPages(): array
    {
        return [
            'index' => ManageBlockTemplates::route('/'),
        ];
    }

    private static function formatBlocksState(mixed $state): string
    {
        if (is_string($state)) {
            return $state;
        }

        if (! is_array($state) || $state === []) {
            $state = [
                ['type' => 'content', 'data' => ['content' => '']],
            ];
        }

        return JsonCodec::encode($state, JSON_PRETTY_PRINT);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function decodeBlocksState(mixed $state): array
    {
        if (is_array($state)) {
            return $state;
        }

        if (! is_string($state) || Str::trim($state) === '') {
            return [];
        }

        return array_values(JsonCodec::decodeArray($state));
    }
}
