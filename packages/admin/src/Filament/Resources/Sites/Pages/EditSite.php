<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Sites\Pages;

use Capell\Admin\Actions\Sites\BuildSiteDeletionImpactDescriptionAction;
use Capell\Admin\Data\Configurators\ConfiguratorContextData;
use Capell\Admin\Enums\ConfiguratorTypeEnum;
use Capell\Admin\Enums\ResourceEnum;
use Capell\Admin\Filament\Actions\DeleteAction;
use Capell\Admin\Filament\Actions\Page\ReplicateSiteAction;
use Capell\Admin\Filament\Actions\Site\CreateSiteAction;
use Capell\Admin\Filament\Actions\Site\ManageSitePermissionsAction;
use Capell\Admin\Filament\Concerns\FixFormDataWithMediaInsideState;
use Capell\Admin\Filament\Concerns\HasConfigurableFormActionPosition;
use Capell\Admin\Filament\Concerns\HasCreateActionOnEditPage;
use Capell\Admin\Filament\Concerns\HasExtensibleRecordHeading;
use Capell\Admin\Filament\Resources\Sites\SiteResource;
use Capell\Admin\Filament\Resources\Sites\Widgets\SiteAlertsWidget;
use Capell\Admin\Support\AdminSurfaceLookup;
use Capell\Admin\Support\Schemas\AdminSchemaExtensionPipeline;
use Capell\Core\Actions\DeleteSiteAction;
use Capell\Core\Actions\PreviewSiteDeletionImpactAction;
use Capell\Core\Actions\RestoreSiteAction;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Site;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Override;

/**
 * @property Site $record
 */
#[On('$refresh')]
class EditSite extends EditRecord
{
    use FixFormDataWithMediaInsideState;
    use HasConfigurableFormActionPosition;
    use HasCreateActionOnEditPage;
    use HasExtensibleRecordHeading;

    /** @return class-string<SiteResource> */
    #[Override]
    public static function getResource(): string
    {
        /** @var class-string<SiteResource> $resource */
        $resource = AdminSurfaceLookup::resource(ResourceEnum::Site);

        return $resource;
    }

    #[Override]
    public function form(Schema $schema): Schema
    {
        $resource = static::getResource();

        return $resource::configuredForm($schema, ConfiguratorContextData::forEdit(
            ConfiguratorTypeEnum::Site,
        ));
    }

    public function getPageTitle(): string|Htmlable
    {
        if (filled(static::$title)) {
            return static::$title;
        }

        return new HtmlString(
            __(
                'capell-admin::heading.edit_site_record',
                [
                    'name' => Str::limit($this->recordTitleText(), 40),
                ],
            ),
        );
    }

    #[Override]
    public function getSubheading(): string|Htmlable|null
    {
        $blueprint = $this->record->blueprint;

        if (! $blueprint instanceof Blueprint) {
            return null;
        }

        return __('capell-admin::heading.site_type', [
            'type' => $blueprint->name,
        ]);
    }

    /** @return array<int, mixed> */
    #[Override]
    protected function getActions(): array
    {
        return $this->getBaseHeaderActions();
    }

    /** @return array<int, mixed> */
    protected function getBaseHeaderActions(): array
    {
        $extenderActions = resolve(AdminSchemaExtensionPipeline::class)->siteHeaderActions();

        return [
            ...$extenderActions,
            RestoreAction::make()
                ->using(fn (Site $record): bool => RestoreSiteAction::run($record)),
            DeleteAction::make()
                ->modalDescription(fn (Site $record): string => BuildSiteDeletionImpactDescriptionAction::run(
                    PreviewSiteDeletionImpactAction::run($record),
                ))
                ->using(fn (Site $record): bool => DeleteSiteAction::run($record)),
            ForceDeleteAction::make(),
            ActionGroup::make([
                ManageSitePermissionsAction::make(),
                CreateSiteAction::make()
                    ->groupedIcon('heroicon-o-plus-circle')
                    ->redirectAfterCreate(),
                ReplicateSiteAction::make()
                    ->hidden($this->record->trashed()),
                Action::make('cache-site')
                    ->label(__('capell-admin::button.cache_site'))
                    ->record($this->getRecord())
                    ->groupedIcon('heroicon-o-arrow-path')
                    ->action(function (Site $record): void {
                        Artisan::call('capell:static-site', ['--site' => $record->getKey()]);

                        Notification::make()
                            ->success()
                            ->title(__('capell-admin::message.cache_site_success'))
                            ->send();
                    }),
            ]),
        ];
    }

    /** @return array<int, mixed> */
    protected function getPositionedFormActions(): array
    {
        return [
            $this->getSaveFormAction(),
            $this->getCancelFormAction(),
        ];
    }

    /** @return array<int, mixed> */
    protected function getPositionedHeaderFormActions(): array
    {
        return [
            $this->getSaveFormAction()
                ->submit(null)
                ->action(fn (): mixed => $this->save()),
            $this->getCancelFormAction(),
        ];
    }

    #[Override]
    protected function getHeaderWidgets(): array
    {
        return [
            SiteAlertsWidget::class,
        ];
    }

    #[Override]
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->fixFormDataWithMediaInsideState($data);
    }

    protected function afterSave(): void
    {
        $this->notifyEditRecordHeadingSaved();
    }

    private function recordTitleText(): string
    {
        $title = $this->getRecordTitle();

        return $title instanceof Htmlable ? $title->toHtml() : $title;
    }
}
