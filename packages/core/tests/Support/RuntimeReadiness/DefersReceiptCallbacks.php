<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support\RuntimeReadiness;

use Closure;

trait DefersReceiptCallbacks
{
    public function deferReceipt(string $abstract, string $key, ?Closure $after = null): void
    {
        $this->callAfterResolving($abstract, static function (DeferredReceiptRegistry $registry) use ($key, $after): void {
            $registry->record($key);
            $after?->__invoke();
        });
    }
}
