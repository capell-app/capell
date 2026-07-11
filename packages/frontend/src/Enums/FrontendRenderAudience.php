<?php

declare(strict_types=1);

namespace Capell\Frontend\Enums;

enum FrontendRenderAudience: string
{
    case Public = 'public';
    case Preview = 'preview';
    case Admin = 'admin';
}
