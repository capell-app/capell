<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Pages\Actions;

use Capell\Admin\Contracts\Backup\PageExporter;
use Capell\Admin\Settings\AdminSettings;
use Capell\Admin\Support\Backup\NullPageExporter;
use Capell\Admin\Support\Backup\PageExportOptions;
use Capell\Core\Models\Page;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Override;

class ExportPagesBulkAction extends BulkAction
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('capell-admin::exchanger.export_page.bulk_label'))
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->authorize('export')
            ->authorizeIndividualRecords('export')
            ->visible(fn (): bool => resolve(AdminSettings::class)->enable_import_export && ! resolve(PageExporter::class) instanceof NullPageExporter)
            ->schema(PageExportOptions::schema(includeAllContexts: true))
            ->action(function (Collection $records, array $data): void {
                $path = resolve(PageExporter::class)->exportPages(
                    $records
                        ->filter(fn (Model $page): bool => $page instanceof Page && $page->exists)
                        ->modelKeys(),
                    PageExportOptions::resolve($data, includeAllContexts: true),
                );

                Notification::make()
                    ->title(__('capell-admin::exchanger.export_page.completed'))
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
