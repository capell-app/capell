<?php

declare(strict_types=1);

namespace Capell\Core\Support\Marketplace;

enum PackageLicenceManifestState
{
    case Free;
    case Paid;
    case Absent;
    case Invalid;
}
