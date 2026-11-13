<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Users;

use Capell\Admin\Enums\RolePreset;

final readonly class UserInvitationData
{
    public function __construct(
        public string $name,
        public string $email,
        public RolePreset $preset,
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
