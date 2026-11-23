<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Models;

use Capell\Tests\Fixtures\Models\User;
use Override;

/**
 * A separate class so Shield's panel user hook boots against the config the test sets, not the
 * configuration the shared User fixture booted with earlier in the run.
 */
final class WebRegistrationUser extends User
{
    public string $guard_name = 'web';

    protected $table = 'users';

    #[Override]
    public function getMorphClass(): string
    {
        return (new User)->getMorphClass();
    }
}
