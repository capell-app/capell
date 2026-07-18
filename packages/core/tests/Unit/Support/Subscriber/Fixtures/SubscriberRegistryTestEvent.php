<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Unit\Support\Subscriber\Fixtures;

enum SubscriberRegistryTestEvent: string
{
    case Published = 'page.published';
}
