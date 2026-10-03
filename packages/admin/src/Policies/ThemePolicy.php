<?php

declare(strict_types=1);

namespace Capell\Admin\Policies;

final class ThemePolicy extends GlobalResourcePolicy
{
    protected const string SUBJECT = 'Theme';
}
