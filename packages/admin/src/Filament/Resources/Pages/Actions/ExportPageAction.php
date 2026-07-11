<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Pages\Actions;

use Capell\Admin\Contracts\Backup\PageExporter;
use Capell\Admin\Settings\AdminSettings;
use Capell\Admin\Support\Backup\NullPageExporter;
use Capell\Admin\Support\Backup\PageExportOptions;
use Capell\Core\Models\Page;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Override;

class ExportPageAction extends Action
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('capell-admin::exchanger.export_page.label'))
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->authorize('export')
            ->visible(fn (): bool => resolve(AdminSettings::class)->enable_import_export && ! resolve(PageExporter::class) instanceof NullPageExporter)
            ->schema($this->optionsForm())
            ->action(function (Page $record, array $data): void {
                $path = resolve(PageExporter::class)->exportPages(
                    [$record->getKey()],
                    $this->buildOptions($data),
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

    /**
     * @return array<int, mixed>
     */
    protected function optionsForm(): array
    {
        return PageExportOptions::schema(includeAllContexts: true);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function buildOptions(array $data): array
    {
        return PageExportOptions::resolve($data, includeAllContexts: true);
    }
}
