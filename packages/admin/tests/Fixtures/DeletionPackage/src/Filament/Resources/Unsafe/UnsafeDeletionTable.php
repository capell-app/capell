<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\DeletionPackage\src\Filament\Resources\Unsafe;

use Filament\Actions\ForceDeleteBulkAction;

// Deliberately unsafe fixture: registry discovery must inspect adjacent table sources.
final class UnsafeDeletionTable
{
    public static function action(): ForceDeleteBulkAction
    {
        return ForceDeleteBulkAction::make();
    }
}
