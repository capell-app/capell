<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Actions\Table;

use Capell\Admin\Filament\Actions\Concerns\CanReplicatePage;
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
            ->label(__('capell-admin::button.replicate_page'))
            ->successNotificationTitle(
                fn (self $action): string => __('capell-admin::message.replicate_success', ['name' => $action->getRecordTitle()]),
            )
            ->modal()
            ->modalWidth(Width::ScreenLarge)
            ->closeModalByClickingAway(false)
            ->fillForm($this->replicatePageFillForm(...))
            ->schema($this->replicatePageSchema(...))
            ->action($this->replicatePageAction(...));
    }
}
