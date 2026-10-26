<?php

declare(strict_types=1);

namespace Capell\Admin\Enums;

enum MediaUploadStatus: string
{
    case Successful = 'successful';
    case Failed = 'failed';
    case Unresolved = 'unresolved';
    case ReconciliationRequired = 'reconciliation_required';
}
