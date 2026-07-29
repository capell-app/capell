<?php

declare(strict_types=1);

namespace Capell\Installer\Enums;

enum InstallerRunMode: string
{
    case BrowserSteps = 'browser-steps';
    case Queued = 'queued';
    case Synchronous = 'synchronous';
}
