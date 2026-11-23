<?php

declare(strict_types=1);

namespace Capell\Marketplace\Enums;

enum MarketplaceInstallSource: string
{
    case LocalUi = 'local-ui';
    case HostedResume = 'hosted-resume';
    case TableHelper = 'table-helper';
    case Cli = 'cli';

    /** Queued by capell:marketplace:auto-update, with no operator present. */
    case Scheduler = 'scheduler';
    case Programmatic = 'programmatic';

    /**
     * Whether nobody is watching when this source's operation runs. An
     * unattended operation may only do exactly what was approved when it was
     * queued; an operator-initiated one is supervised.
     */
    public function isUnattended(): bool
    {
        return $this === self::Scheduler;
    }
}
