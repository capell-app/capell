<?php

declare(strict_types=1);

namespace Capell\Admin\Enums;

enum AdminPanelFailureCategory: string
{
    case PermissionDenied = 'permission_denied';
    case ParseError = 'parse_error';
    case MissingPanel = 'missing_panel';
    case UnsupportedShape = 'unsupported_shape';
    case ExistingConflict = 'existing_conflict';
    case Validation = 'validation';
}
