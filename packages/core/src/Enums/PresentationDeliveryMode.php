<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

enum PresentationDeliveryMode: string
{
    case ServerRendered = 'server_rendered';
    case LazyFragment = 'lazy_fragment';
}
