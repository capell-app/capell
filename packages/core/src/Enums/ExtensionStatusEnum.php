<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

enum ExtensionStatusEnum: string
{
    case Installing = 'installing';
    case Enabled = 'enabled';
    case Disabled = 'disabled';
    case Failed = 'failed';
    case Uninstalled = 'uninstalled';
}
