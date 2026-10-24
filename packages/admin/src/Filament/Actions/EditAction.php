<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Actions;

use Filament\Resources\RelationManagers\RelationManager;
use Illuminate\Auth\Access\Response;
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
        // Preserve component denials and the read-only relation-manager guard.
        $this->authorize(static function (self $action, Model $record): Response {
            $livewire = $action->getHasActionsLivewire();

            if ($livewire instanceof RelationManager && $livewire->isReadOnly()) {
                return Response::deny();
            }

            return $livewire?->getDefaultActionAuthorizationResponse($action)
                ?? Gate::inspect('update', $record);
        });
    }
}
