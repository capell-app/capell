<?php

declare(strict_types=1);

namespace Capell\Marketplace\Enums;

use Capell\Marketplace\Exceptions\MarketplaceCatalogueException;
use Illuminate\Http\Client\ConnectionException;
use Throwable;

enum MarketplaceCatalogueFailure: string
{
    case Connection = 'connection';
    case Configuration = 'configuration';
    case Service = 'service';
    case Verification = 'verification';

    public static function fromThrowable(Throwable $throwable): self
    {
        return match (true) {
            $throwable instanceof MarketplaceCatalogueException => $throwable->failure,
            $throwable instanceof ConnectionException => self::Connection,
            default => self::Verification,
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Connection => (string) __('capell-marketplace::marketplace.catalogue_failures.connection'),
            self::Configuration => (string) __('capell-marketplace::marketplace.catalogue_failures.configuration'),
            self::Service => (string) __('capell-marketplace::marketplace.catalogue_failures.service'),
            self::Verification => (string) __('capell-marketplace::marketplace.catalogue_failures.verification'),
        };
    }
}
