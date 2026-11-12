<?php

declare(strict_types=1);

namespace Capell\CompanionFixture;

use Filament\Pages\BasePage;
use Override;

/** A companion package's admin page, which depends on Capell's package security. */
class CompanionWorkspacePage extends BasePage
{
    public const string BODY = 'companion workspace page served';

    #[Override]
    public function __invoke(): string
    {
        return self::BODY;
    }
}
