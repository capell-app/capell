<?php

declare(strict_types=1);

namespace Capell\Admin\Macros\Filament;

use Closure;
use Filament\Forms\Components\Contracts\CanBeLengthConstrained;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\HtmlString;

/**
 * @mixin TextInput|Textarea
 */
class HelperCountTextMacro
{
    /**
     * Add Character Count
     *
     * @return Closure(): Field
     *
     * @return-closure-this TextInput|Textarea
     */
    public function helperCountText(): Closure
    {
        return fn (): Field => $this
            ->belowContent(function (Field&CanBeLengthConstrained $component): ?HtmlString {
                $maxLength = $component->getMaxLength();
                if ($maxLength === null || $maxLength === 0) {
                    return null;
                }

                $charactersText = __('characters');

                return new HtmlString(
                    <<<HTML
                            <span
                                 x-cloak
                                 x-show="(\$state ?? '').length > 0"
                                 x-text="(\$state ?? '').length + ' / {$maxLength} {$charactersText}'"
                                 x-bind:class="{ 'text-red-500': (\$state ?? '').length > {$maxLength} }"
                             ></span>
                        HTML
                );
            });
    }
}
