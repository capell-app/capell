<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Actions;

use Capell\Admin\Filament\Actions\Concerns\CanReplicateRecord;
use Capell\Admin\Filament\Contracts\HasPageResource;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Model;
use Override;

class ReplicateAction extends \Filament\Actions\ReplicateAction
{
    use CanReplicateRecord;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->modalWidth(Width::Medium)
            ->groupedIcon('heroicon-o-square-2-stack')
            ->successNotificationTitle(
                fn (self $action): string => __(
                    'capell-admin::message.replicate_success',
                    ['name' => $action->getRecordTitle()],
                ),
            )
            ->successRedirectUrl(function (Model $replica, EditRecord|CreateRecord|ListRecords|HasPageResource $livewire): string {
                $resource = $livewire::getResource();

                return $resource::getUrl('edit', ['record' => $replica]);
            })
            ->schema($this->replicateRecordSchema(...))
            ->action($this->replicateRecordAction(...));
    }
}
