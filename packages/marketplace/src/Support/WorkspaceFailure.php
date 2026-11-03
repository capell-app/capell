<?php

declare(strict_types=1);

namespace Capell\Marketplace\Support;

enum WorkspaceFailure: string
{
    case Untrusted = 'untrusted';
    case Indeterminate = 'indeterminate';
}
