<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Redirects\Pages;

use Capell\Admin\Filament\Actions\CreateAction;
use Capell\Admin\Filament\Concerns\HasImportExportHeaderActions;
use Capell\Admin\Filament\Exports\RedirectExporter;
use Capell\Admin\Filament\Resources\Redirects\RedirectResource;
use Capell\Admin\Settings\AdminSettings;
use Capell\Admin\Support\AdminSurfaceLookup;
use Capell\Core\Enums\RedirectStatusCodeEnum;
use Filament\Actions\ExportAction;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Gate;
use Override;

class ManageRedirects extends ManageRecords
{
    use HasImportExportHeaderActions;

    /** @return class-string<RedirectResource> */
    #[Override]
    public static function getResource(): string
    {
        /** @var class-string<RedirectResource> $resource */
        $resource = AdminSurfaceLookup::resource('Redirect');

        return $resource;
    }

    #[Override]
    public function getSubheading(): string|Htmlable|null
    {
        return __('capell-admin::hints.redirects');
    }

    #[Override]
    public function mount(): void
    {
        parent::mount();

        if (! request()->boolean('create_redirect')) {
            return;
        }

        $siteId = request()->integer('site_id');
        $languageId = request()->integer('language_id');
        $url = request()->string('url')->toString();
        $targetUrl = request()->string('target_url')->toString();

        $createRedirectData = [
            'site_id' => $siteId !== 0 ? $siteId : null,
            'language_id' => $languageId !== 0 ? $languageId : null,
            'url' => $url !== '' ? $url : null,
            'target_url' => $targetUrl !== '' ? $targetUrl : null,
            'status_code' => request()->integer('status_code', RedirectStatusCodeEnum::Permanent->value),
        ];

        $this->mountAction('create', $createRedirectData);

        if (! is_array($this->mountedActions)) {
            return;
        }

        $mountedActionIndex = array_key_last($this->mountedActions);

        if ($mountedActionIndex === null) {
            return;
        }

        $mountedActionData = $this->mountedActions[$mountedActionIndex]['data'] ?? [];

        if (! is_array($mountedActionData)) {
            $mountedActionData = [];
        }

        $this->mountedActions[$mountedActionIndex]['data'] = [
            ...$mountedActionData,
            ...array_filter(
                $createRedirectData,
                static fn (mixed $value): bool => $value !== null,
            ),
        ];
    }

    #[Override]
    protected function getActions(): array
    {
        return $this->prependImportHeaderAction([
            CreateAction::make(),
            ExportAction::make()
                ->authorize(fn (): bool => Gate::allows('export', RedirectResource::getModel()))
                ->visible(fn (): bool => resolve(AdminSettings::class)->enable_import_export)
                ->exporter(RedirectExporter::class),
        ]);
    }
}
