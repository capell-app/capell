<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Activity;

use Capell\Tests\Fixtures\Models\User;
use Override;

/** Global audit ownership without the super-admin role's permission bypass. */
final class GlobalAuditUser extends User
{
    protected $table = 'users';

    protected string $guard_name = 'web';

    public static function fromUser(User $user): self
    {
        $actor = new self;
        $actor->setRawAttributes($user->getRawOriginal(), sync: true);
        $actor->exists = $user->exists;
        $actor->setConnection($user->getConnectionName());

        return $actor;
    }

    #[Override]
    public function isGlobalAdmin(): bool
    {
        return true;
    }

    #[Override]
    public function getMorphClass(): string
    {
        return (new User)->getMorphClass();
    }
}
