<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support;

use stdClass;

enum PackageSurfaceRegistrarTestModel: string
{
    case Example = stdClass::class;
}
