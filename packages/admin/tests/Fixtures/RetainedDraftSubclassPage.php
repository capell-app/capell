<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures;

use Capell\Core\Models\Page;

class RetainedDraftSubclassPage extends Page
{
    protected $table = 'pages';
}
