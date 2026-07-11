<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support\Fixtures\Autoload;

use Capell\Core\Enums\Attribute\Component;
use Capell\Core\Enums\Attribute\EnumAttributeHelper;

enum AttributeHelperTestEnum: string
{
    use EnumAttributeHelper;

    #[Component('hero-component')]
    case Hero = 'hero';

    case Plain = 'plain';
}
