<?php

declare(strict_types=1);

namespace Capell\Core\Contracts;

use Capell\Core\Data\SiteAccessContextData;
use Capell\Core\Data\SiteAccessPolicyData;

interface SiteAccessPolicyProvider
{
    public function key(): string;

    public function resolve(SiteAccessContextData $context): ?SiteAccessPolicyData;
}
