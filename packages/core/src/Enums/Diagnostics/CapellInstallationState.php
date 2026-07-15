<?php

declare(strict_types=1);

namespace Capell\Core\Enums\Diagnostics;

enum CapellInstallationState: string
{
    case NotInstalled = 'not_installed';
    case Partial = 'partial';
    case Installed = 'installed';
}
