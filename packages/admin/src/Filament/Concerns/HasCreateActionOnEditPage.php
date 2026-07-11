<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Concerns;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Fix issue with creating a new record in a modal and saving relationships
 * where it was updating the current record instead of creating a new one.
 *
 * @mixin EditRecord
 */
trait HasCreateActionOnEditPage
{
    public function getDefaultActionRecord(Action $action): ?Model
    {
        if ($action instanceof CreateAction) {
            return null;
        }

        return $this->record;
    }
}
