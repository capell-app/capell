<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts\Media;

use Filament\Forms\Components\Field;

/** Builds an Admin-owned Filament field from Core's neutral media configuration. */
interface AdminMediaFieldFactory
{
    public function make(string $name): Field;
}
