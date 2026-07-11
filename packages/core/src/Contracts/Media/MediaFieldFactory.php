<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Media;

use Filament\Forms\Components\Field;

/**
 * Builds the Filament form Field for uploading/picking media.
 *
 * This contract is the swap point for the admin UI. The default binding in
 * core resolves to Capell\Core\Support\Media\SpatieMediaFieldFactory (the
 * Spatie file-upload component); admin overrides it with a decorator that
 * adds translated label + max-size; the capell/media-library plugin rebinds
 * it entirely to return its own curator picker.
 */
interface MediaFieldFactory
{
    public function make(string $name): Field;
}
