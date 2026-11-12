<?php

declare(strict_types=1);

namespace Capell\CompanionFixture;

/** Admin code shipped by a Capell companion package, which depends on Capell's package security. */
class CompanionAdminController
{
    public const string BODY = 'companion admin code served';

    public function __invoke(): string
    {
        return self::BODY;
    }
}
