<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Languages\Pages;

use Capell\Admin\Actions\SetupSiteLanguageAction;
use Capell\Admin\Enums\ResourceEnum;
use Capell\Admin\Filament\Actions\CreateAction;
use Capell\Admin\Filament\Concerns\HasImportExportHeaderActions;
use Capell\Admin\Filament\Concerns\Validate\LanguageValidation;
use Capell\Admin\Filament\Contracts\ValidatesDelete;
use Capell\Admin\Filament\Resources\Languages\LanguageResource;
use Capell\Admin\Filament\Resources\Languages\Widgets\LanguagesAlertsWidget;
use Capell\Admin\Support\AdminSurfaceLookup;
use Capell\Admin\Support\SiteScope;
use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Override;

class ManageLanguages extends ManageRecords implements ValidatesDelete
{
    use HasImportExportHeaderActions;
    use LanguageValidation;

    /** @return class-string<LanguageResource> */
    #[Override]
    public static function getResource(): string
    {
        /** @var class-string<LanguageResource> $resource */
        $resource = AdminSurfaceLookup::resource(ResourceEnum::Language);

        return $resource;
    }

    #[Override]
    public function getSubheading(): string|Htmlable|null
    {
        return __('capell-admin::generic.language_info');
    }

    #[Override]
    protected function getHeaderWidgets(): array
    {
        return [
            LanguagesAlertsWidget::class,
        ];
    }

    #[Override]
    protected function getActions(): array
    {
        return $this->prependImportHeaderAction([
            CreateAction::make()
                ->modalDescription(__('capell-admin::generic.create_language_info'))
                ->after(function (self $livewire, array $data, Language $record, CreateAction $action): void {
                    $mountedActions = $livewire->mountedActions;
                    $mountedActionData = is_array($mountedActions)
                        ? (array) ($mountedActions[array_key_last($mountedActions)]['data'] ?? [])
                        : [];
                    $actionData = [
                        ...$mountedActionData,
                        ...$action->getRawData(),
                    ];
                    if (isset($actionData['setup']) && $actionData['setup'] !== '' && isset($actionData['setup_sites']) && is_array($actionData['setup_sites']) && $actionData['setup_sites'] !== []) {
                        /** @var Builder<Site> $siteQuery */
                        $siteQuery = SiteScope::applyForCurrentActor(Site::query(), 'id')
                            ->whereIn('id', $actionData['setup_sites']);

                        $siteQuery->each(function (Site $site) use ($record): void {
                            SetupSiteLanguageAction::run($site, $record);
                        });
                    }
                }),
        ]);
    }
}
