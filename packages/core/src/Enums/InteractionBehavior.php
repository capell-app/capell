<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

enum InteractionBehavior: string
{
    case Modal = 'modal';
    case SlideOver = 'slide_over';
    case InlineReveal = 'inline_reveal';
    case ReplaceRegion = 'replace_region';
}
