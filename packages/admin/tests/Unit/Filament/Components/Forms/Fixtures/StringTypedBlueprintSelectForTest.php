<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Unit\Filament\Components\Forms\Fixtures;

use Capell\Admin\Filament\Components\Forms\BlueprintSelect as BaseBlueprintSelect;
use Override;

/**
 * A select pointed at a subject key no package registered.
 */
final class StringTypedBlueprintSelectForTest extends BaseBlueprintSelect
{
    #[Override]
    protected function setUp(?string $label = null): void
    {
        parent::setUp($label);

        $this->subject('custom');
    }
}
