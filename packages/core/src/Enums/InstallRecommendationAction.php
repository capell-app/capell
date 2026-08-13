<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

enum InstallRecommendationAction: string
{
    case Select = 'select';
    case Confirm = 'confirm';
    case Custom = 'custom';
    case Skip = 'skip';
}
