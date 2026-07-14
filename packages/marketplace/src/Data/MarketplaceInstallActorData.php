<?php

declare(strict_types=1);

namespace Capell\Marketplace\Data;

use Illuminate\Contracts\Auth\Authenticatable;
use Spatie\LaravelData\Data;

final class MarketplaceInstallActorData extends Data
{
    public function __construct(
        public readonly string $identifier,
        public readonly ?string $email = null,
        public readonly bool $system = false,
    ) {}

    public static function fromAuthenticatable(Authenticatable $actor): self
    {
        $identifier = $actor->getAuthIdentifier();
        $email = method_exists($actor, 'getAttribute') ? $actor->getAttribute('email') : null;

        return new self(
            identifier: is_scalar($identifier) ? (string) $identifier : 'authenticated-user',
            email: is_string($email) && $email !== '' ? $email : null,
        );
    }

    public static function system(string $identifier = 'capell-system'): self
    {
        return new self($identifier, system: true);
    }
}
