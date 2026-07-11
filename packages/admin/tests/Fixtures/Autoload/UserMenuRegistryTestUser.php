<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Autoload;

use Illuminate\Contracts\Auth\Authenticatable;

final class UserMenuRegistryTestUser implements Authenticatable
{
    public function __construct(
        private readonly int $id,
        public readonly string $name,
    ) {}

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier(): int
    {
        return $this->id;
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getAuthPassword(): string
    {
        return '';
    }

    public function getRememberToken(): ?string
    {
        return null;
    }

    public function setRememberToken(mixed $value): void {}

    public function getRememberTokenName(): string
    {
        return 'remember_token';
    }
}
