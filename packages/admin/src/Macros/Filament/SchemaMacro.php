<?php

declare(strict_types=1);

namespace Capell\Admin\Macros\Filament;

use Closure;
use Filament\Schemas\Schema;

/**
 * @mixin Schema
 */
class SchemaMacro
{
    /**
     * @return Closure(): bool
     *
     * @return-closure-this Schema
     */
    public function isCreating(): Closure
    {
        return fn (): bool => in_array($this->getOperation(), ['create', 'createOption', 'replicate'], true);
    }

    /**
     * @return Closure(): bool
     *
     * @return-closure-this Schema
     */
    public function isEditing(): Closure
    {
        return fn (): bool => in_array($this->getOperation(), ['edit', 'editOption'], true);
    }
}
