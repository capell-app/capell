<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Unit\Filament\Components\Tables\Columns\Fixtures;

use Capell\Admin\Filament\Components\Tables\Columns\MediaLibraryImageColumn;

final class TestableMediaLibraryImageColumn extends MediaLibraryImageColumn
{
    public function exposeNormaliseState(mixed $state): mixed
    {
        return $this->normaliseState($state);
    }
}
