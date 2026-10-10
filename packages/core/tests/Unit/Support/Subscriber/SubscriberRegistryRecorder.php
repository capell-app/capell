<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Unit\Support\Subscriber;

final class SubscriberRegistryRecorder
{
    /** @var array<int, array{event: string, context: object, source: string}> */
    public array $handled = [];

    /** @var array<int, array{event: string, context: object, source: string}> */
    public array $validated = [];

    /** @var array<class-string, string> */
    public array $labels = [];

    /** @var array<string, bool> */
    public array $validateReturns = [];
}
