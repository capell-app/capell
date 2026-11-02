<?php

declare(strict_types=1);

namespace Capell\Marketplace\Exceptions;

use RuntimeException;
use Throwable;

final class MarketplaceRemoteFailureException extends RuntimeException
{
    public function __construct(public readonly int $httpStatus)
    {
        parent::__construct((string) __('capell-marketplace::marketplace.errors.remote_failure'));
    }

    public static function diagnostic(Throwable $throwable): string
    {
        return $throwable instanceof self ? 'remote_failure:' . $throwable->httpStatus : 'operation_failed';
    }
}
