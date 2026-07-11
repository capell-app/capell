<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Actions\Page;

use Capell\Core\Actions\PageDeletedAction;
use Capell\Core\Contracts\Pageable;
use Filament\Actions\DeleteAction;
use Override;

class DeletePageAction extends DeleteAction
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->icon('heroicon-m-trash')
            ->action(function (): void {
                $result = $this->process(static function (Pageable $record): bool {
                    PageDeletedAction::run($record);

                    return true;
                });

                if ($result !== true) {
                    $this->failure();

                    return;
                }

                $this->success();
            });
    }
}
