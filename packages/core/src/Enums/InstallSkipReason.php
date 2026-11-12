<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

/** Why a recommended package was left out of a default or preset install. */
enum InstallSkipReason: string
{
    case NotInPublicChannel = 'not_in_public_channel';
    case MayNeedLicence = 'may_need_licence';
}
