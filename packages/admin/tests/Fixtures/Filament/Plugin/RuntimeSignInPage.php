<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Filament\Plugin;

use Filament\Auth\Pages\Login;
use Override;

final class RuntimeSignInPage extends Login
{
    public const string BODY = 'custom sign-in page served';

    #[Override]
    public function __invoke(): string
    {
        return self::BODY;
    }
}
