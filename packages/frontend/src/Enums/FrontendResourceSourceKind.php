<?php

declare(strict_types=1);

namespace Capell\Frontend\Enums;

enum FrontendResourceSourceKind: string
{
    case Vite = 'vite';
    case PublicPath = 'public';
    case External = 'external';
    case Inline = 'inline';
}
