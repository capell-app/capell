<?php

declare(strict_types=1);

namespace Capell\Marketplace\Exceptions;

use Capell\Marketplace\Enums\MarketplaceConnectionFailure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class MarketplaceConnectionStartException extends RuntimeException
{
    public function __construct(public readonly MarketplaceConnectionFailure $failure, ?Throwable $previous = null)
    {
        parent::__construct(match ($failure) {
            MarketplaceConnectionFailure::Configuration => 'APP_URL must include a valid host before connecting Marketplace.',
            MarketplaceConnectionFailure::Approval => 'Marketplace returned an invalid approval URL.',
            MarketplaceConnectionFailure::Service => 'Marketplace could not start the connection.',
            MarketplaceConnectionFailure::Storage => 'The local account connection session could not be saved.',
            MarketplaceConnectionFailure::Unreachable => 'Marketplace could not be reached.',
            MarketplaceConnectionFailure::Rejected => 'Marketplace rejected the account connection request.',
        }, previous: $previous);
    }

    /**
     * A log-safe description of why a start failed. Query failures keep only the driver's
     * error (never the SQL bindings, which hold the state and code verifier), and remote
     * failures keep only the HTTP status (never the response body).
     */
    public static function diagnostic(Throwable $throwable): string
    {
        $cause = $throwable instanceof self ? ($throwable->getPrevious() ?? $throwable) : $throwable;

        $message = match (true) {
            $cause instanceof QueryException => $cause->getPrevious()?->getMessage() ?? 'Database query failed (SQLSTATE ' . $cause->getCode() . ').',
            $cause instanceof MarketplaceRemoteFailureException => 'Marketplace responded with HTTP ' . $cause->httpStatus . '.',
            $cause instanceof ConnectionException, $cause instanceof self => $cause->getMessage(),
            default => $cause::class,
        };

        return Str::limit(str_replace(["\r", "\n"], ' ', $message), 500);
    }
}
