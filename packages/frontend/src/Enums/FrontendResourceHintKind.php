<?php

declare(strict_types=1);

namespace Capell\Frontend\Enums;

enum FrontendResourceHintKind: string
{
    case Preload = 'preload';
    case ModulePreload = 'modulepreload';
    case Preconnect = 'preconnect';
    case DnsPrefetch = 'dns-prefetch';
}
