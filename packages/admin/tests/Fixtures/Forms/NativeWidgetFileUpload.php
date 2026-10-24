<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Forms;

use Filament\Forms\Components\FileUpload;
use Override;

// Mirrors the native subclass used by companion Layout Builder widgets.
final class NativeWidgetFileUpload extends FileUpload
{
    /** @return array<mixed> */
    #[Override]
    public function getValidationRules(): array
    {
        $this->getRawState();

        return parent::getValidationRules();
    }

    #[Override]
    public function getRawState(): mixed
    {
        $state = parent::getRawState();

        if (is_string($state)) {
            $this->state($state);

            return parent::getRawState();
        }

        return $state;
    }
}
