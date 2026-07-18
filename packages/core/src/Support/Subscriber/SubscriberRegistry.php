<?php

declare(strict_types=1);

namespace Capell\Core\Support\Subscriber;

/**
 * @template TContract of object
 *
 * @extends SubscriberManager<TContract>
 */
class SubscriberRegistry extends SubscriberManager {}
