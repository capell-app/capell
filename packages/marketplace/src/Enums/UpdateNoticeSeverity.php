<?php

declare(strict_types=1);

namespace Capell\Marketplace\Enums;

enum UpdateNoticeSeverity: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';
}
