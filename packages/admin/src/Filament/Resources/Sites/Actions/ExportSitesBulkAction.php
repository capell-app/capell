<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Sites\Actions;

use Capell\Admin\Contracts\Backup\PageExporter;
use Capell\Admin\Settings\AdminSettings;
use Capell\Admin\Support\Backup\NullPageExporter;
use Capell\Admin\Support\Backup\PageExportOptions;
use Capell\Core\Models\Site;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Override;

class ExportSitesBulkAction extends BulkAction
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('capell-admin::exchanger.export_site.bulk_label'))
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->authorize('export')
            ->authorizeIndividualRecords('export')
            ->visible(fn (): bool => resolve(AdminSettings::class)->enable_import_export && ! resolve(PageExporter::class) instanceof NullPageExporter)
            ->schema(PageExportOptions::schema())
            ->action(function (Collection $records, array $data): void {
                /** @var Collection<int, Site> $sites */
                $sites = $records;

                $path = resolve(PageExporter::class)->exportSites(
                    $sites
                        ->filter(fn (Site $site): bool => $site->exists)
                        ->modelKeys(),
                    PageExportOptions::resolve($data, omitAllContexts: true),
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
