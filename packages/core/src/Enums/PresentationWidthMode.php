<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

enum PresentationWidthMode: string
{
    case Inherit = 'inherit';
    case Full = 'full';
    case Container = 'container';
    case Custom = 'custom';
}
