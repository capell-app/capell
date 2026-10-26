<?php

declare(strict_types=1);

namespace Capell\Marketplace\Exceptions;

use Capell\Marketplace\Enums\MarketplaceConnectionFailure;
use RuntimeException;

final class MarketplaceConnectionStartException extends RuntimeException
{
    public function __construct(public readonly MarketplaceConnectionFailure $failure)
    {
        parent::__construct(match ($failure) {
            MarketplaceConnectionFailure::Configuration => 'APP_URL must include a valid host before connecting Marketplace.',
            MarketplaceConnectionFailure::Approval => 'Marketplace returned an invalid approval URL.',
            MarketplaceConnectionFailure::Service => 'Marketplace could not start the connection.',
        });
    }
}
