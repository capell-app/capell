<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms\Layout;

use Capell\Admin\Filament\Actions\HintEditAction;
use Capell\Admin\Filament\Components\Forms\DefaultToggle;
use Capell\Admin\Filament\Components\Forms\KeyTextInput;
use Capell\Admin\Filament\Components\Forms\MediaLibraryFileUpload;
use Capell\Admin\Filament\Components\Forms\NameKeyGroup;
use Capell\Admin\Filament\Components\Forms\SiteSelect;
use Capell\Admin\Filament\Components\Forms\StatusToggle;
use Capell\Admin\Filament\Components\Forms\ThemeSelect;
use Capell\Admin\Filament\Resources\Themes\ThemeResource;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Site;
use Capell\Core\Support\Permissions\SiteAccess;
use Capell\Core\Support\Themes\LayoutViewRegistry;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

class DetailsSchema
{
    /**
     * @return array<int, mixed>
     */
    public static function make(Schema $schema): array
    {
        return [
            Section::make(__('capell-admin::form.layout_identity'))
                ->description(__('capell-admin::generic.layout_identity_description'))
                ->columns()
                ->columnSpanFull()
                ->schema([
                    NameKeyGroup::make(
                        modifyKey: fn (KeyTextInput $component): KeyTextInput => $component->unique(
                            table: Layout::class,
                            ignoreRecord: $schema->getOperation() !== 'replicate',
                            modifyRuleUsing: fn (Unique $rule) => $rule->withoutTrashed(),
                        ),
                    ),
                    GroupSelect::make('group')
                        ->helperText(__('capell-admin::generic.layout_group_info')),
                    SiteSelect::make('site_id')
                        ->helperText(__('capell-admin::generic.layout_site_info'))
                        ->withEditLink(),
                    self::themeSelect($schema),
                ]),

            Section::make(__('capell-admin::form.layout_availability'))
                ->description(__('capell-admin::generic.layout_availability_description'))
                ->columnSpanFull()
                ->columns()
                ->schema([
                    TextInput::make('order')
                        ->label(__('capell-admin::form.order'))
                        ->helperText(__('capell-admin::generic.layout_order_info'))
                        ->required()
                        ->numeric()
                        ->default(function (): int {
                            /** @var class-string<Layout> $model */
                            $model = Layout::class;

                            return SiteAccess::current()->query($model)->max('order') + 1;
                        })
                        ->minValue(0)
                        ->step(1),
                    DefaultToggle::make('default'),
                    StatusToggle::make('status'),
                ]),

            Section::make(__('capell-admin::generic.layout_impact_preview'))
                ->description(__('capell-admin::generic.layout_impact_preview_description'))
                ->columnSpanFull()
                ->hiddenOn(['create', 'createOption', 'replicate'])
                ->schema([
                    View::make('capell-admin::filament.forms.layout-impact-preview')
                        ->columnSpanFull(),
                ]),

            Section::make(__('capell-admin::form.preview'))
                ->description(__('capell-admin::generic.layout_preview_description'))
                ->columnSpanFull()
                ->hiddenOn(['create', 'createOption', 'replicate'])
                ->schema([
                    Group::make()
                        ->statePath('admin')
                        ->schema([
                            MediaLibraryFileUpload::make('image')
                                ->label(__('capell-admin::form.preview_image')),
                        ]),
                ]),

            Section::make(__('capell-admin::generic.files'))
                ->description(__('capell-admin::generic.layout_files_description'))
                ->statePath('meta')
                ->columns()
                ->columnSpanFull()
                ->hiddenOn(['create', 'createOption', 'replicate'])
                ->schema([
                    Select::make('master_file')
                        ->searchable()
                        ->options(fn (): array => resolve(LayoutViewRegistry::class)->masterOptions())
                        ->rule(fn () => Rule::in(array_keys(resolve(LayoutViewRegistry::class)->masterOptions())))
                        ->label(__('capell-admin::form.master_file'))
                        ->helperText(fn (?string $state): string => self::viewHelp($state, resolve(LayoutViewRegistry::class)->masterOptions(), (string) __('capell-admin::generic.layout_master_file_info')))
                        ->placeholder(__('capell-admin::layout_views.default')),
                    Select::make('layout_file')
                        ->searchable()
                        ->options(fn (): array => resolve(LayoutViewRegistry::class)->layoutOptions())
                        ->rule(fn () => Rule::in(array_keys(resolve(LayoutViewRegistry::class)->layoutOptions())))
                        ->label(__('capell-admin::form.layout_file'))
                        ->helperText(fn (?string $state): string => self::viewHelp($state, resolve(LayoutViewRegistry::class)->layoutOptions(), (string) __('capell-admin::generic.layout_layout_file_info')))
                        ->placeholder(__('capell-admin::layout_views.default')),
                ]),
        ];
    }

    /** @param array<string, string> $options */
    private static function viewHelp(?string $state, array $options, string $help): string
    {
        if (filled($state) && ! array_key_exists($state, $options)) {
            return $help . ' ' . __('capell-admin::layout_views.unavailable', ['view' => $state]);
        }

        return $options === [] ? $help . ' ' . __('capell-admin::layout_views.registration') : $help;
    }

    private static function themeSelect(Schema $schema): ThemeSelect
    {
        return ThemeSelect::make('theme_id')
            ->hint(__('capell-admin::generic.layout_theme_info'))
            ->hintAction(
                HintEditAction::make('edit-theme')
                    ->visible(fn (?int $state, Get $get): bool => (blank($state)) && (bool) $get('site_id'))
                    ->url(
                        function (HintEditAction $action, Get $get): string {
                            /** @var class-string<Site> $model */
                            $model = Site::class;

                            $themeId = SiteAccess::current()->query($model)
                                ->where('id', $get('site_id'))
                                ->value('theme_id');

                            return ThemeResource::getUrl(
                                parameters: [
                                    'tableAction' => EditAction::getDefaultName(),
                                    'tableActionRecord' => $themeId,
                                ],
                            );
                        },
                        shouldOpenInNewTab: true,
                    ),
            )
            ->when(
                $schema->isCreating(),
                fn (ThemeSelect $component): ThemeSelect => $component->withCreateForm(),
                fn (ThemeSelect $component): ThemeSelect => $component->withEditForm(),
            );
    }
}
