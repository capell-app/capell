<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Tables\Actions;

use Capell\Admin\Filament\Actions\Concerns\CanReplicateRecord;
use Filament\Support\Enums\Width;
use Override;

class ReplicateAction extends \Filament\Actions\ReplicateAction
{
    use CanReplicateRecord;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->modalWidth(Width::Medium)
            ->successNotificationTitle(
                fn (self $action): string|array => __(
                    'capell-admin::message.replicate_success',
                    ['name' => $action->getRecordTitle()],
                ),
            )
            ->mutateRecordDataUsing($this->replicateRecordData(...))
            ->schema($this->replicateRecordSchema(...))
            ->action($this->replicateRecordAction(...));
    }
}
