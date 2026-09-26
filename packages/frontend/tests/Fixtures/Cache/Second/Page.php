<?php

declare(strict_types=1);

namespace Capell\Frontend\Tests\Fixtures\Cache\Second;

use Capell\Core\Models\Page as CorePage;
use Override;

final class Page extends CorePage
{
    protected $table = 'pages';

    #[Override]
    public function getMorphClass(): string
    {
        return (new CorePage)->getMorphClass();
    }
}
