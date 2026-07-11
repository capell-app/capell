<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

enum InteractionTargetType: string
{
    case Widget = 'widget';
    case Fragment = 'fragment';
    case Url = 'url';
    case PublicAction = 'public_action';
}
