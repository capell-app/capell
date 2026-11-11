<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

enum SiteSpecPlanAction: string
{
    case Create = 'create';
    case Update = 'update';
    case Skip = 'skip';
}
