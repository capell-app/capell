<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

enum ExtensionLicenceStatus: string
{
    case Free = 'free';
    case None = 'none';
    case Purchased = 'purchased';
    case Active = 'active';
    case Expired = 'expired';
    case Revoked = 'revoked';
    case Invalid = 'invalid';
    case Unverified = 'unverified';
    case DomainMismatch = 'domain_mismatch';
}
