<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Themes\Pages;

use Capell\Admin\Actions\Themes\CreateAvailableThemeAction;
use Capell\Admin\Actions\Themes\CreateCustomThemeAction;
use Capell\Admin\Actions\Themes\ResolveThemeLibraryAction;
use Capell\Admin\Enums\ResourceEnum;
use Capell\Admin\Filament\Concerns\HasImportExportHeaderActions;
use Capell\Admin\Filament\Concerns\Validate\ThemeValidation;
use Capell\Admin\Filament\Contracts\ValidatesDelete;
use Capell\Admin\Filament\Resources\Themes\ThemeResource;
use Capell\Admin\Filament\Resources\Themes\Widgets\ThemesAlertsWidget;
use Capell\Admin\Support\AdminSurfaceLookup;
use Capell\Admin\Support\Schemas\AdminSchemaExtensionPipeline;
use Capell\Core\Models\Theme;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Override;

class ManageThemes extends ManageRecords implements ValidatesDelete
{
    use HasImportExportHeaderActions;
    use ThemeValidation;

    /** @var array{installed: array<int, mixed>, available: array<int, mixed>, pending: int, pendingInstalls: list<array{name: string, package: string, command: string}>, warnings: array<int, mixed>}|null */
    private ?array $themeLibraryData = null;

    /** @return class-string<ThemeResource> */
    #[Override]
    public static function getResource(): string
    {
        /** @var class-string<ThemeResource> $resource */
        $resource = AdminSurfaceLookup::resource(ResourceEnum::Theme);

        return $resource;
    }

    #[Override]
    public function getSubheading(): string|Htmlable|null
    {
        return __('capell-admin::theme-library.subheading');
    }

    #[Override]
    public function getTitle(): string|Htmlable
    {
        return __('capell-admin::theme-library.title');
    }

    /** @return array{installed: array<int, mixed>, available: array<int, mixed>, pending: int, pendingInstalls: list<array{name: string, package: string, command: string}>, warnings: array<int, mixed>} */
    public function getThemeLibraryData(): array
    {
        return $this->themeLibraryData ??= ResolveThemeLibraryAction::run();
    }

    public function canCreateThemes(): bool
    {
        return Gate::allows('create', Theme::class);
    }

    #[Override]
    public function content(Schema $schema): Schema
    {
        /** @var view-string $overviewView */
        $overviewView = 'capell-admin::filament.resources.themes.theme-library-overview';

        return $schema
            ->components([
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE),
                View::make($overviewView)
                    ->columnSpanFull(),
                EmbeddedTable::make(),
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER),
            ]);
    }

    public function notifyThemeColorsChanged(Theme $theme): void
    {
        Notification::make('theme-colors-updated')
            ->title(__('capell-admin::notification.theme_colors_updated'))
            ->info()
            ->send();
    }

    public function createAvailableTheme(string $themeKey): void
    {
        Gate::authorize('create', Theme::class);

        try {
            $theme = CreateAvailableThemeAction::run($themeKey);
        } catch (ValidationException $validationException) {
            $message = collect($validationException->errors())->flatten()->first();

            Notification::make('theme-create-failed')
                ->title(is_string($message) && $message !== ''
                    ? $message
                    : $this->translationString('capell-admin::theme-library.validation.diagnostics_block_create'))
                ->danger()
                ->send();

            return;
        }

        $this->themeLibraryData = null;
        $this->resetTable();

        Notification::make('theme-created')
            ->title(__('capell-admin::theme-library.messages.created', ['theme' => $theme->name]))
            ->success()
            ->send();
    }

    public function availableThemeCanBeCreated(string $themeKey): bool
    {
        $card = collect($this->getThemeLibraryData()['available'])
            ->first(fn (mixed $availableTheme): bool => $availableTheme->themeKey === $themeKey);

        return $card !== null && $card->diagnostics->isValid();
    }

    #[Override]
    protected function getHeaderWidgets(): array
    {
        return [
            ThemesAlertsWidget::class,
        ];
    }

    #[Override]
    protected function getActions(): array
    {
        $themeHeaderActions = resolve(AdminSchemaExtensionPipeline::class)->resourceHeaderActions(static::class);

        return $this->prependImportHeaderAction([
            ...$themeHeaderActions,
            ActionGroup::make([
                $this->createThemeAction(),
                $this->installThemeAction(),
            ])
                ->label(__('capell-admin::theme-library.actions.add_theme'))
                ->icon('heroicon-o-plus')
                ->button()
                ->dropdownPlacement('bottom-end'),
        ]);
    }

    private function translationString(string $key): string
    {
        $value = __($key);

        return is_string($value) ? $value : $key;
    }

    private function createThemeAction(): Action
    {
        return Action::make('createTheme')
            ->label(__('capell-admin::theme-library.actions.create_new_theme'))
            ->icon('heroicon-o-paint-brush')
            ->slideOver()
            ->modalWidth(Width::ScreenLarge)
            ->modalHeading(__('capell-admin::theme-library.actions.create_new_theme'))
            ->modalSubmitActionLabel(__('capell-admin::theme-library.actions.create_new_theme'))
            ->schema([
                Section::make(__('capell-admin::theme-library.labels.add_theme_custom'))
                    ->schema([
                        TextInput::make('custom_name')
                            ->label(__('capell-admin::table.name'))
                            ->required()
                            ->maxLength(255),
                        TextInput::make('custom_key')
                            ->label(__('capell-admin::table.key'))
                            ->required()
                            ->alphaDash()
                            ->unique(Theme::class, 'key')
                            ->maxLength(255),
                        TextInput::make('custom_description')
                            ->label(__('capell-admin::table.description'))
                            ->maxLength(255),
                        Toggle::make('custom_default')
                            ->label(__('capell-admin::form.default')),
                        Toggle::make('custom_status')
                            ->label(__('capell-admin::table.status'))
                            ->default(true),
                    ]),
            ])
            ->action(function (array $data): void {
                $this->handleCreateTheme($data);
            });
    }

    private function installThemeAction(): Action
    {
        return Action::make('installTheme')
            ->label(__('capell-admin::theme-library.actions.install_theme'))
            ->icon('heroicon-o-cube')
            ->slideOver()
            ->modalWidth(Width::ScreenLarge)
            ->modalHeading(__('capell-admin::theme-library.actions.install_theme'))
            ->modalSubmitActionLabel(__('capell-admin::theme-library.actions.install_theme'))
            ->schema([
                Section::make(__('capell-admin::theme-library.labels.add_theme_package'))
                    ->schema([
                        Select::make('available_theme_key')
                            ->label(__('capell-admin::theme-library.labels.available_theme'))
                            ->options(fn (ManageThemes $livewire): array => collect($livewire->getThemeLibraryData()['available'])
                                ->mapWithKeys(fn (mixed $card): array => [$card->themeKey => sprintf(
                                    '%s (%s)',
                                    $card->title,
                                    trans_choice('capell-admin::theme-library.labels.preset_count', count($card->presetNames), ['count' => count($card->presetNames)]),
                                )])
                                ->all())
                            ->disableOptionWhen(fn (string $value, ManageThemes $livewire): bool => ! $livewire->availableThemeCanBeCreated($value))
                            ->searchable()
                            ->required(),
                    ]),
                Section::make(__('capell-admin::theme-library.labels.add_theme_marketplace'))
                    ->schema([
                        Text::make(__('capell-admin::theme-library.help.marketplace')),
                    ]),
            ])
            ->action(function (array $data): void {
                $this->handleInstallTheme($data);
            });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function handleCreateTheme(array $data): void
    {
        Gate::authorize('create', Theme::class);

        $theme = CreateCustomThemeAction::run(
            name: (string) ($data['custom_name'] ?? ''),
            key: (string) ($data['custom_key'] ?? ''),
            description: (string) ($data['custom_description'] ?? ''),
            default: (bool) ($data['custom_default'] ?? false),
            status: (bool) ($data['custom_status'] ?? true),
        );

        $this->themeLibraryData = null;
        $this->resetTable();

        Notification::make('theme-created')
            ->title(__('capell-admin::theme-library.messages.created', ['theme' => $theme->name]))
            ->success()
            ->send();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function handleInstallTheme(array $data): void
    {
        $this->createAvailableTheme((string) ($data['available_theme_key'] ?? ''));
    }
}
