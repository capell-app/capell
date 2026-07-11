<?php

declare(strict_types=1);

namespace Capell\Admin\Macros\Filament;

use Capell\Core\Actions\ColorTypeDetectorAction;
use Closure;
use Filament\Forms\Components\ColorPicker;

/**
 * @mixin ColorPicker
 */
class ColorPickerMacro
{
    /**
     * Auto set the format based on the state value
     *
     * @return Closure(): ColorPicker
     *
     * @return-closure-this ColorPicker
     */
    public function autoFormat(): Closure
    {
        return fn (): ColorPicker => $this
            ->format(function (ColorPicker $component, ?string $state): string {
                if ($state === null) {
                    return 'rgba';
                }

                $format = ColorTypeDetectorAction::run($state);

                return match ($format) {
                    'hex', 'rgba', 'hsl', 'rgb' => $format,
                    default => 'hex',
                };
            });
    }
}
