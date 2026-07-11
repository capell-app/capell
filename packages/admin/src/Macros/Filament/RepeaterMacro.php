<?php

declare(strict_types=1);

namespace Capell\Admin\Macros\Filament;

use Closure;
use Filament\Forms\Components\Repeater;

/**
 * @mixin Repeater
 */
class RepeaterMacro
{
    /**
     * @return Closure(): Repeater
     *
     * @return-closure-this Repeater
     */
    public function compactRepeater(): Closure
    {
        return fn (): Repeater => $this
            ->extraAttributes(['class' => 'compact-repeater']);
    }
}
