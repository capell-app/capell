<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Actions;

use Filament\Resources\RelationManagers\RelationManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Override;

class EditAction extends \Filament\Actions\EditAction
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        // The action is named "edit", but Laravel and host policies use "update".
        // Preserve the read-only relation-manager guard from Filament's default.
        $this->authorize(function (Model $record): bool {
            $livewire = $this->getHasActionsLivewire();

            return (! $livewire instanceof RelationManager || ! $livewire->isReadOnly())
                && Gate::allows('update', $record);
        });
    }
}
