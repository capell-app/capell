<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Pages;

use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Capell\Admin\Actions\NormalizeDashboardFilamentWidgetSettingsAction;
use Capell\Admin\Actions\PersistMissingSettingsDefaultsAction;
use Capell\Admin\Actions\Reports\BuildReportVisibilityFormStateAction;
use Capell\Admin\Actions\Reports\NormalizeReportVisibilitySettingsAction;
use Capell\Admin\Filament\Settings\Schemas\DashboardSettingsSchema;
use Capell\Admin\Filament\Settings\Schemas\ReportsSettingsSchema;
use Capell\Admin\Settings\AdminSettings;
use Capell\Core\Contracts\SettingsContract;
use Capell\Core\Events\FrontendSurrogateKeysInvalidated;
use Capell\Core\Models\Site;
use Capell\Core\Settings\CoreSettings;
use Capell\Core\Support\Settings\SettingsSchemaRegistry;
use Capell\Core\ThemeStudio\Assets\ThemeTokenStore;
use Capell\Core\ThemeStudio\Data\BrandProfileData;
use Capell\Core\ThemeStudio\Settings\ThemeStudioSettings;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Facades\FilamentView;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Override;
use Throwable;
use UnitEnum;

class SettingsPage extends AbstractAdminSettingsPage
{
    use HasPageShield;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::Cog6Tooth;

    protected static string $settings = CoreSettings::class;

    protected static ?string $slug = 'settings';

    protected static bool $shouldRegisterNavigation = true;

    protected static ?int $navigationSort = 9;

    #[Override]
    public static function getNavigationLabel(): string
    {
        return (string) __('capell-admin::navigation.settings');
    }

    #[Override]
    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return (string) __('capell-admin::navigation.group_system');
    }

    #[Override]
    public function getTitle(): string|Htmlable
    {
        return __('capell-admin::heading.settings');
    }

    #[Override]
    public function getSubheading(): string|Htmlable|null
    {
        return __('capell-admin::generic.settings_info');
    }

    #[Override]
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make()
                    ->tabs([
                        ...$this->packageSettingsTabs($schema),
                        $this->dashboardSettingsTab($schema),
                        $this->reportsSettingsTab($schema),
                        $this->coreSettingsTab($schema),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    #[Override]
    public function save(): void
    {
        if (! $this->canEdit()) {
            return;
        }

        try {
            $this->beginDatabaseTransaction();

            $this->callHook('beforeValidate');

            $data = $this->form->getState();

            $this->validateThemeStudioBrandProfile($data);

            $this->callHook('afterValidate');

            $data = $this->mutateFormDataBeforeSave($data);

            $this->callHook('beforeSave');

            $themeStudioSettingsSaved = false;
            $themeStudioTokenIssues = [];

            foreach ($this->getSettingsGroupMap() as $group => $settingsClass) {
                $values = $data[$group] ?? null;

                if (! is_array($values)) {
                    continue;
                }

                if ($settingsClass === AdminSettings::class) {
                    $this->dispatch('refresh-sidebar');
                }

                PersistMissingSettingsDefaultsAction::run($settingsClass);

                $settings = resolve($settingsClass);

                if ($settings instanceof ThemeStudioSettings && is_array($values['brandProfile'] ?? null)) {
                    $values['brandProfile'] = BrandProfileData::from($settings->brandProfile)
                        ->merge($values['brandProfile'])
                        ->toArray();
                }

                $settings->fill($values);
                $settings->save();

                if ($settings instanceof ThemeStudioSettings) {
                    $themeStudioSettingsSaved = true;
                    $themeStudioTokenIssues = resolve(ThemeTokenStore::class)
                        ->issues(BrandProfileData::from($settings->brandProfile));
                }
            }

            $this->callHook('afterSave');
        } catch (Halt $exception) {
            $exception->shouldRollbackDatabaseTransaction()
                ? $this->rollBackDatabaseTransaction()
                : $this->commitDatabaseTransaction();

            return;
        } catch (Throwable $exception) {
            $this->rollBackDatabaseTransaction();

            throw $exception;
        }

        $this->commitDatabaseTransaction();

        if ($themeStudioSettingsSaved) {
            event(new FrontendSurrogateKeysInvalidated($this->allSiteSurrogateKeys()));
        }

        $this->rememberData();

        if ($themeStudioTokenIssues !== []) {
            Notification::make()
                ->title(__('capell-admin::message.theme_studio_token_fallback_heading'))
                ->body($this->themeStudioTokenFallbackBody($themeStudioTokenIssues))
                ->warning()
                ->send();
        } else {
            $this->getSavedNotification()?->send();
        }

        if (! in_array($redirectUrl = $this->getRedirectUrl(), [null, '', '0'], true)) {
            $this->redirect($redirectUrl, navigate: FilamentView::hasSpaMode($redirectUrl));
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    #[Override]
    protected function mutateFormDataBeforeFill(array $data): array
    {
        foreach ($this->getSettingsGroupMap() as $group => $settingsClass) {
            $settings = resolve($settingsClass);
            $data[$group] = $settings->toArray();

            if ($settings instanceof AdminSettings) {
                $data[$group]['report_visibility'] = BuildReportVisibilityFormStateAction::run($settings);
            }
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    #[Override]
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $adminSettings = $data['admin'] ?? null;

        if (is_array($adminSettings)) {
            $reportVisibility = $adminSettings['report_visibility'] ?? [];

            if (is_array($reportVisibility)) {
                $adminSettings['enabled_reports_by_role'] = NormalizeReportVisibilitySettingsAction::run($reportVisibility);
            }

            unset($adminSettings['report_visibility']);

            $data['admin'] = NormalizeDashboardFilamentWidgetSettingsAction::run($adminSettings);
        }

        $themeStudioSettings = $data['theme_studio'] ?? null;

        if (is_array($themeStudioSettings)) {
            $data['theme_studio'] = $this->normalizeThemeStudioSettings($themeStudioSettings);
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    private function validateThemeStudioBrandProfile(array $data): void
    {
        $brandProfile = data_get($data, 'theme_studio.brandProfile');

        if (! is_array($brandProfile)) {
            return;
        }

        $settings = resolve(ThemeStudioSettings::class);
        $mergedBrandProfile = BrandProfileData::from($settings->brandProfile)->merge($brandProfile);
        $issues = resolve(ThemeTokenStore::class)->issues($mergedBrandProfile);

        if ($issues === []) {
            return;
        }

        $message = $this->themeStudioTokenFallbackBody($issues);

        throw ValidationException::withMessages([
            'data.theme_studio.brandProfile.primaryColor' => [$message],
            'data.theme_studio.brandProfile.accentColor' => [$message],
            'data.theme_studio.brandProfile.neutralColor' => [$message],
            'data.theme_studio.brandProfile.surfaceColor' => [$message],
            'data.theme_studio.brandProfile.foregroundColor' => [$message],
        ]);
    }

    /**
     * @param  array<int, string>  $issues
     */
    private function themeStudioTokenFallbackBody(array $issues): string
    {
        if ($issues === []) {
            return __('capell-admin::message.theme_studio_token_fallback_body');
        }

        return __('capell-admin::message.theme_studio_token_fallback_body')
            . PHP_EOL . PHP_EOL
            . __('capell-admin::message.theme_studio_token_fallback_issues', [
                'issues' => implode(PHP_EOL, $issues),
            ]);
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function normalizeThemeStudioSettings(array $settings): array
    {
        $brandProfile = $settings['brandProfile'] ?? null;

        if (! is_array($brandProfile)) {
            return $settings;
        }

        foreach ($brandProfile as $key => $value) {
            if ($value instanceof BackedEnum) {
                $brandProfile[$key] = $value->value;
            }
        }

        $settings['brandProfile'] = $brandProfile;

        return $settings;
    }

    /**
     * @return list<string>
     */
    private function allSiteSurrogateKeys(): array
    {
        return array_values(Site::query()
            ->pluck('id')
            ->map(fn (int $siteId): string => 'site-' . $siteId)
            ->all());
    }

    /**
     * @return array<int, mixed>
     */
    private function getSettingsFormSchema(string $group, Schema $schema): array
    {
        $schemas = resolve(SettingsSchemaRegistry::class)->getSchemas($group);

        if ($group === 'admin') {
            $schemas = collect($schemas)
                ->reject(fn (string $schemaClass): bool => class_basename($schemaClass) === 'DashboardSettingsSchema')
                ->all();
        }

        if (count($schemas) > 1) {
            return [
                Tabs::make($group . '-settings')
                    ->tabs($this->settingsSchemaTabs($schemas, $schema))
                    ->columnSpanFull(),
            ];
        }

        $components = [];

        foreach ($schemas as $schemaClass) {
            $components = array_merge($components, $schemaClass::make($schema));
        }

        return $components;
    }

    /**
     * @return array<string, class-string<SettingsContract>>
     */
    private function getSettingsGroupMap(): array
    {
        $registry = resolve(SettingsSchemaRegistry::class);
        $map = [];

        foreach ($registry->getFirstPartyGroups() as $group) {
            $settingsClass = $registry->getSettingsClass($group);
            if ($settingsClass === null) {
                continue;
            }

            if (! is_a($settingsClass, SettingsContract::class, true)) {
                continue;
            }

            $map[$group] = $settingsClass;
        }

        return $map;
    }

    /**
     * @param  array<string, class-string>  $schemas
     * @return array<int, Tab>
     */
    private function settingsSchemaTabs(array $schemas, Schema $schema): array
    {
        $tabs = [];

        foreach ($schemas as $schemaClass) {
            $tabs[] = Tab::make($this->settingsSchemaLabel($schemaClass))
                ->schema($schemaClass::make($schema))
                ->columns();
        }

        return $tabs;
    }

    private function settingsSchemaLabel(string $schemaClass): string
    {
        return match (class_basename($schemaClass)) {
            'CoreSettingsSchema' => (string) __('capell-admin::form.settings_tab_language_identity'),
            'CoreContentSettingsSchema' => (string) __('capell-admin::form.settings_tab_content_controls'),
            'AdminSettingsSchema' => (string) __('capell-admin::form.settings_tab_admin_experience'),
            'DashboardSettingsSchema' => (string) __('capell-admin::form.settings_tab_dashboard'),
            default => Str::headline(class_basename($schemaClass)),
        };
    }

    private function dashboardSettingsTab(Schema $schema): Tab
    {
        return Tab::make(__('capell-admin::form.settings_tab_dashboard'))
            ->key('dashboard')
            ->icon(Heroicon::OutlinedSquares2x2)
            ->statePath('admin')
            ->schema(DashboardSettingsSchema::make($schema));
    }

    private function reportsSettingsTab(Schema $schema): Tab
    {
        return Tab::make(__('capell-admin::reports.settings_tab'))
            ->key('reports')
            ->icon(Heroicon::OutlinedDocumentText)
            ->statePath('admin')
            ->schema(ReportsSettingsSchema::make($schema));
    }

    /**
     * @return array<int, Tab>
     */
    private function packageSettingsTabs(Schema $schema): array
    {
        $registry = resolve(SettingsSchemaRegistry::class);
        $tabs = [];

        foreach ($registry->getFirstPartyGroups() as $group) {
            if ($group === 'core') {
                continue;
            }

            $metadata = $registry->getMetadata($group);

            $tabs[] = Tab::make($metadata?->getLabel() ?? Str::headline($group))
                ->icon($metadata?->icon)
                ->statePath($group)
                ->columns()
                ->schema($this->getSettingsFormSchema($group, $schema));
        }

        return $tabs;
    }

    private function coreSettingsTab(Schema $schema): Tab
    {
        return Tab::make(__('capell-admin::generic.core'))
            ->icon(Heroicon::OutlinedCog6Tooth)
            ->statePath('core')
            ->columns()
            ->schema($this->getSettingsFormSchema('core', $schema));
    }
}
