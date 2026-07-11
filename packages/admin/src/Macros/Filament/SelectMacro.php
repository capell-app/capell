<?php

declare(strict_types=1);

namespace Capell\Admin\Macros\Filament;

use Closure;
use Filament\Forms\Components\Select;

/**
 * @mixin Select
 */
class SelectMacro
{
    /**
     * @return Closure(): Select
     *
     * @return-closure-this Component
     */
    public function autoDefault(): Closure
    {
        return fn (): Select => $this->default(
            fn (Select $component): string|int|null => array_key_first($component->getOptions()),
        );
    }
}
