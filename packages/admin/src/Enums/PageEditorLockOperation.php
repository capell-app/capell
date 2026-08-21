<?php

declare(strict_types=1);

namespace Capell\Admin\Enums;

enum PageEditorLockOperation: string
{
    case Inspect = 'inspect';
    case Open = 'open';
    case Save = 'save';
    case TakeOver = 'takeover';
}
