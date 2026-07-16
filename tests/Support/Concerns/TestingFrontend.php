<?php

declare(strict_types=1);

namespace Capell\Tests\Support\Concerns;

use Capell\Tests\AbstractTestCase;
use Illuminate\Support\Facades\App;

/**
 * @mixin AbstractTestCase
 */
trait TestingFrontend
{
    public function setUpTestingFrontend(): void
    {
        if (! App::environment('testing')) {
            return;
        }

        $this->withoutVite();
    }
}
