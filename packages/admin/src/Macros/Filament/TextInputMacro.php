<?php

declare(strict_types=1);

namespace Capell\Admin\Macros\Filament;

use Closure;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\TextInput;

/**
 * @mixin TextInput
 */
class TextInputMacro
{
    /**
     * Upper Case
     *
     * @return Closure(): Field
     *
     * @return-closure-this TextInput
     */
    public function uppercase(): Closure
    {
        return fn (): Field => $this
            ->rules('uppercase:' . ($this->getMaxLength() ?? ''))
            ->extraAlpineAttributes(fn (TextInput $component): array => [
                'x-on:input' => sprintf("\$wire.set('%s', \$event.target.value.toUpperCase(), false)", $this->getStatePath()),
            ]);
    }
}
