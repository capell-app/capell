<?php

declare(strict_types=1);

use Capell\Marketplace\Tests\MarketplaceTestCase;

pest()->extend(MarketplaceTestCase::class)->in('Feature', 'Unit');
