<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Filament\Plugin;

use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;
use Override;

final class PaletteWorkspaceResource extends Resource
{
    #[Override]
    public static function canAccess(): bool
    {
        return Filament::auth()->user()?->can('ViewAny:Workspace') ?? false;
    }

    #[Override]
    public static function getUrl(?string $name = null, array $parameters = [], bool $isAbsolute = true, ?string $panel = null, ?Model $tenant = null, bool $shouldGuessMissingParameters = false, ?string $configuration = null): string
    {
        return url('/admin/publishing-studio');
    }
}
