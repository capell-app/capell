<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Roles\Pages\Concerns;

use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Group;

trait HasTopFormActions
{
    public function getFormContentComponent(): Component
    {
        return Group::make([
            $this->getFormActionsContentComponent(),
            EmbeddedSchema::make('form'),
        ]);
    }

    public function hasFormWrapper(): bool
    {
        return false;
    }
}
