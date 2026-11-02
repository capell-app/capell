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

    /**
     * The failure reason a flow record may store: a bounded code for remote and
     * unexpected failures, or the message of a failure Capell authored itself.
     */
    public static function diagnostic(Throwable $throwable): string
    {
        return match (true) {
            $throwable instanceof self => 'remote_failure:' . $throwable->httpStatus,
            $throwable instanceof MarketplaceInstallFlowException => $throwable->getMessage(),
            default => 'operation_failed',
        };
    }
}
