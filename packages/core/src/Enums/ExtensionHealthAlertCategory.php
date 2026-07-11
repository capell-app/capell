<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

enum ExtensionHealthAlertCategory: string
{
    case Verification = 'verification';
    case Fraud = 'fraud';
    case Security = 'security';
    case Update = 'update';
    case Licence = 'licence';
    case Package = 'package';
}
