<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Users;

use Capell\Admin\Enums\RolePreset;
use Spatie\LaravelData\Data;

final class UserInvitationData extends Data
{
    public function __construct(
        public readonly string $name,
        public readonly string $email,
        public readonly RolePreset $preset,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromForm(array $data): self
    {
        return new self(
            name: trim((string) ($data['name'] ?? '')),
            email: mb_strtolower(trim((string) ($data['email'] ?? ''))),
            preset: RolePreset::from((string) ($data['preset'] ?? '')),
        );
    }
}
