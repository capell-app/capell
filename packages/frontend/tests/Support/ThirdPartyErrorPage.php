<?php

declare(strict_types=1);

namespace Capell\Frontend\Tests\Support;

use Capell\Core\Models\Page;
use Override;

final class ThirdPartyErrorPage extends Page
{
    protected $table = 'pages';

    #[Override]
    public function getMorphClass(): string
    {
        return (new Page)->getMorphClass();
    }
}
