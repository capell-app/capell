<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Extensions;

use Capell\Core\Support\Extensions\ExtensionContributionReceiptContext;
use Closure;

interface BootsExtensionContributionReceiptContext
{
    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function withContext(ExtensionContributionReceiptContext $context, Closure $callback): mixed;
}
