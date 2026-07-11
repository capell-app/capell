<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Sites\Actions;

use Capell\Admin\Contracts\Backup\PageExporter;
use Capell\Admin\Settings\AdminSettings;
use Capell\Admin\Support\Backup\NullPageExporter;
use Capell\Admin\Support\Backup\PageExportOptions;
use Capell\Core\Models\Site;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Override;

class ExportSiteAction extends Action
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('capell-admin::exchanger.export_site.label'))
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->authorize('export')
            ->visible(fn (): bool => resolve(AdminSettings::class)->enable_import_export && ! resolve(PageExporter::class) instanceof NullPageExporter)
            ->schema(PageExportOptions::schema())
            ->action(function (Site $record, array $data): void {
                $path = resolve(PageExporter::class)->exportSites(
                    [$record->getKey()],
                    PageExportOptions::resolve($data),
                );

                Notification::make()
                    ->title(__('capell-admin::exchanger.export_site.completed'))
                    ->body(basename($path))
                    ->success()
                    ->send();
            });
    }

    public static function getDefaultName(): ?string
    {
        return 'export';
    }
}
