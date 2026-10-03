<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Actions;

use Capell\Admin\Actions\ValidateForceDeleteAction;
use Capell\Admin\Filament\Contracts\ValidatesDelete;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Override;

final class ForceDeleteAction extends \Filament\Actions\ForceDeleteAction
{
    #[Override]
    public function isConfirmationRequired(): bool
    {
        return true;
    }

    #[Override]
    public function callBefore(): mixed
    {
        $record = $this->getRecord();
        throw_unless($record instanceof Model, LogicException::class, 'Permanent deletion requires a model record.');

        $livewire = $this->getLivewire();
        if (! ValidateForceDeleteAction::run($record, $livewire instanceof ValidatesDelete ? $livewire : null)) {
            $this->halt();
        }

        return parent::callBefore();
    }
}
