<?php

declare(strict_types=1);

namespace Capell\Admin\Macros\Filament;

use Closure;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;

/**
 * @mixin Component
 */
class ComponentMacro
{
    /**
     * @return Closure(bool|Closure $contained): Section
     *
     * @return-closure-this Section
     */
    public function contained(): Closure
    {
        return function (bool|Closure $contained = true): Section {
            if ($this->evaluate($contained)) {
                /** @var view-string $view */
                $view = 'filament-schemas::components.grid';

                $this->view($view);
            }

            return $this;
        };
    }
}
