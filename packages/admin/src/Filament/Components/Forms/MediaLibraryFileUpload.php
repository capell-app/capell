<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms;

use Capell\Admin\Contracts\Media\AdminMediaFieldFactory;
use Filament\Forms\Components\Field;

/**
 * Admin UI entry point around the Admin-owned media field adapter.
 *
 * Existing schemas call MediaLibraryFileUpload::make('name') — that entry
 * Existing schemas keep this entry point; the Core Filament-facing contract
 * remains available only as a 1.x compatibility adapter.
 */
final class MediaLibraryFileUpload
{
    public static function make(string $name): Field
    {
        return resolve(AdminMediaFieldFactory::class)->make($name);
    }
}
