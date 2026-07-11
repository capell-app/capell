<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Unit\Filament\Components\Forms\Fixtures;

use Capell\Admin\Filament\Components\Forms\BlueprintSelect as BaseBlueprintSelect;
use Capell\Core\Enums\BlueprintSubjectEnum;

final class StringTypedBlueprintSelectForTest extends BaseBlueprintSelect
{
    protected null|BlueprintSubjectEnum|string $type = 'custom';
}
