<?php

declare(strict_types=1);

namespace Capell\Admin\Policies;

final class RolePolicy extends GlobalResourcePolicy
{
    protected const string SUBJECT = 'Role';
}
