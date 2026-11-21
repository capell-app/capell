<?php

declare(strict_types=1);

namespace Capell\Core\Enums\Rendering;

enum FrontendResourcePlacement: string
{
    case Head = 'head';
    case BodyEnd = 'body-end';
}
