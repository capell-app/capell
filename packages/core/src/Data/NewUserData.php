<?php

declare(strict_types=1);

namespace Capell\Core\Data;

use Spatie\LaravelData\Data;

final class NewUserData extends Data
{
    public function __construct(
        public readonly string $name,
        public readonly string $email,
        public readonly string $password,
        public readonly ?string $roleName = null,
    ) {}
}
