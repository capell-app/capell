<?php

declare(strict_types=1);

namespace Capell\Frontend\Enums;

enum FrontendResourceHintAs: string
{
    case Style = 'style';
    case Script = 'script';
    case Font = 'font';
    case Image = 'image';
    case Fetch = 'fetch';
}
