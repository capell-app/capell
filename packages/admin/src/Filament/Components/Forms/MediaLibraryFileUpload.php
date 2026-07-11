<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms;

use Capell\Core\Contracts\Media\MediaFieldFactory;
use Filament\Forms\Components\Field;

/**
 * Back-compat facade around the MediaFieldFactory contract.
 *
 * Existing schemas call MediaLibraryFileUpload::make('name') — that entry
 * point still works but now resolves the bound MediaFieldFactory from the
 * container. The Spatie backend and the capell/media-library plugin swap
 * by binding different MediaFieldFactory implementations.
 */
final class MediaLibraryFileUpload
{
    public static function make(string $name): Field
    {
        return resolve(MediaFieldFactory::class)->make($name);
    }
}
