<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support;

final class BadReturnCreateModelInterceptor
{
    public function beforeCreate(): string
    {
        return 'invalid';
    }
}
