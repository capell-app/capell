<?php

declare(strict_types=1);

namespace Capell\Marketplace\Exceptions;

use RuntimeException;

final class PurchaseRequiredException extends RuntimeException
{
    public function __construct(
        public readonly string $purchaseUrl,
        string $message = 'Purchase is required before this plugin can be installed.',
    ) {
        parent::__construct($message);
    }
}
