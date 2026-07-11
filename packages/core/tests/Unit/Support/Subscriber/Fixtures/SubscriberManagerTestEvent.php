<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Unit\Support\Subscriber\Fixtures;

enum SubscriberManagerTestEvent: string
{
    case Published = 'page.published';
}
