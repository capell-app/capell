<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures;

use Capell\Admin\Filament\Resources\Media\MediaResource;
use Override;

final class CreationDeniedMediaResource extends MediaResource
{
    protected static ?string $slug = 'media';

    #[Override]
    public static function canCreate(): bool
    {
        return false;
    }
}
