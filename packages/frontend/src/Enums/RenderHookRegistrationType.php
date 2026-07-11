<?php

declare(strict_types=1);

namespace Capell\Frontend\Enums;

enum RenderHookRegistrationType: string
{
    case View = 'view';
    case InlineBlade = 'inline-blade';
    case Callable = 'callable';
    case ExtensionClass = 'class';
    case LegacyString = 'legacy-string';
}
