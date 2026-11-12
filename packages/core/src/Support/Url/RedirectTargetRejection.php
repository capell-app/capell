<?php

declare(strict_types=1);

namespace Capell\Core\Support\Url;

enum RedirectTargetRejection: string
{
    case Empty = 'empty';
    case ControlCharacter = 'control_character';
    case ProtocolRelative = 'protocol_relative';
    case RelativeNotAllowed = 'relative_not_allowed';
    case Scheme = 'scheme';
    case Malformed = 'malformed';
    case Credentials = 'credentials';
    case Host = 'host';
}
