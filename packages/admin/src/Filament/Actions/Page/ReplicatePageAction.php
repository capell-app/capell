<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Actions\Page;

use Capell\Admin\Enums\ResourceEnum;
use Capell\Admin\Filament\Actions\Concerns\CanReplicatePage;
use Capell\Admin\Filament\Resources\Pages\PageResource;
use Capell\Admin\Support\AdminSurfaceLookup;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Page;
use Filament\Actions\ReplicateAction;
use Filament\Support\Enums\Width;
use Override;

class ReplicatePageAction extends ReplicateAction
{
    use CanReplicatePage;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->slideOver()
            ->groupedIcon('heroicon-o-square-2-stack')
            ->modalWidth(Width::ScreenLarge)
            ->closeModalByClickingAway(false)
            ->successNotificationTitle(
                fn (self $action): string|array => __(
                    'capell-admin::message.replicate_success',
                    ['name' => $action->getRecordTitle()],
                ),
            )
            ->successRedirectUrl(function (Page $replica): string {
                $type = $replica->blueprint()->first();
                $configuredResource = $type instanceof Blueprint ? ($type->admin['resource'] ?? null) : null;
                $resourceClass = AdminSurfaceLookup::resource(ResourceEnum::Page, $configuredResource);

                if ($resourceClass === '0') {
                    $resourceClass = PageResource::class;
                }

                return $resourceClass::getUrl('edit', ['record' => $replica]);
            })
            ->schema($this->replicatePageSchema(...))
            ->fillForm($this->replicatePageFillForm(...))
            ->action($this->replicatePageAction(...));
    }
}
