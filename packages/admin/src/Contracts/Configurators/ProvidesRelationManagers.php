<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts\Configurators;

use Filament\Resources\RelationManagers\RelationGroup;
use Filament\Resources\RelationManagers\RelationManager;
use Illuminate\Database\Eloquent\Model;

interface ProvidesRelationManagers
{
    /**
     * @return array<class-string<RelationManager>|class-string<RelationGroup>>
     */
    public static function relationManagers(Model $record): array;
}
