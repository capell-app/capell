<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Unit\Filament\Concerns\Fixtures;

use Capell\Admin\Filament\Concerns\Validate\PageValidation;
use Capell\Admin\Filament\Contracts\ValidatesDelete;

final class PageDeleteValidationHarness implements ValidatesDelete
{
    use PageValidation;
}
