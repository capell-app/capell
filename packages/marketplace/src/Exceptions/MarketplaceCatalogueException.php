<?php

declare(strict_types=1);

namespace Capell\Marketplace\Exceptions;

use Capell\Marketplace\Enums\MarketplaceCatalogueFailure;
use RuntimeException;

final class MarketplaceCatalogueException extends RuntimeException
{
    public function __construct(
        public readonly MarketplaceCatalogueFailure $failure,
        string $message = 'Marketplace catalogue unavailable.',
    ) {
        parent::__construct($message);
    }
}
