<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support\RuntimeReadiness;

use Capell\Core\Tests\Support\RuntimeLifecycleFixture;

final class MainDeferredReceiptProvider extends RuntimeLifecycleFixture
{
    use DefersReceiptCallbacks;
}
