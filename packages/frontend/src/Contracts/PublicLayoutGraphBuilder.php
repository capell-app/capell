<?php

declare(strict_types=1);

namespace Capell\Frontend\Contracts;

use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;

interface PublicLayoutGraphBuilder
{
    public function build(Layout $layout, Page $page, Language $language): ?object;
}
