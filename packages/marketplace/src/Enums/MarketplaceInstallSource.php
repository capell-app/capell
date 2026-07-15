<?php

declare(strict_types=1);

namespace Capell\Marketplace\Enums;

enum MarketplaceInstallSource: string
{
    case LocalUi = 'local-ui';
    case HostedResume = 'hosted-resume';
    case TableHelper = 'table-helper';
    case Cli = 'cli';
    case Programmatic = 'programmatic';
}
