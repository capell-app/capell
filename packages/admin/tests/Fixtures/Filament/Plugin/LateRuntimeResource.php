<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Filament\Plugin;

use Filament\Resources\Resource;
use Override;

final class LateRuntimeResource extends Resource
{
    #[Override]
    public static function canViewAny(): bool
    {
        return true;
    }
}
