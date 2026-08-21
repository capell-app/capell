<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts;

use Capell\Admin\Data\EnumPresentationData;
use UnitEnum;

interface EnumPresentationContributor
{
    public const string TAG = 'capell.admin.enum-presentation-contributor';

    public function present(UnitEnum $enum): ?EnumPresentationData;
}
