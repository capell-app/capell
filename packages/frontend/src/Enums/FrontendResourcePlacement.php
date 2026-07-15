<?php

declare(strict_types=1);

namespace Capell\Frontend\Enums;

enum FrontendResourcePlacement: string
{
    case Head = 'head';
    case BodyEnd = 'body-end';
}
