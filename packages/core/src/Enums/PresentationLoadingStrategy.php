<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

enum PresentationLoadingStrategy: string
{
    case Eager = 'eager';
    case Visible = 'visible';
    case Interaction = 'interaction';
    case Idle = 'idle';
}
