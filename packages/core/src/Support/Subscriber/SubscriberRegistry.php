<?php

declare(strict_types=1);

namespace Capell\Core\Support\Subscriber;

use BackedEnum;
use Capell\Core\Support\Subscriber\Contracts\Subscriber;
use Capell\Core\Support\Subscriber\Contracts\ValidatingSubscriber;
use InvalidArgumentException;

/**
 * @template TContract of object
 */
class SubscriberRegistry
{
    /** @var array<class-string<TContract>, class-string<TContract>> */
    protected array $subscribers = [];

    /**
     * @param  class-string<TContract>  $subscriber
     */
    public function subscribe(string $subscriber): void
    {
        $contract = $this->subscriberContract();

        if (! is_subclass_of($subscriber, $contract) && $subscriber !== $contract) {
            throw new InvalidArgumentException(
                sprintf('Subscriber [%s] must implement [%s].', $subscriber, $contract),
            );
        }

        $this->subscribers[$subscriber] = $subscriber;
    }

    /**
     * @param  class-string<TContract>  $subscriber
     */
    public function unsubscribe(string $subscriber): void
    {
        unset($this->subscribers[$subscriber]);
    }

    /**
     * @return array<int, class-string<TContract>>
     */
    public function getSubscribers(): array
    {
        return array_values($this->subscribers);
    }

    /**
     * @param  class-string<TContract>  $subscriber
     */
    public function hasSubscriber(string $subscriber): bool
    {
        return isset($this->subscribers[$subscriber]);
    }

    public function notifySubscribers(string|BackedEnum $event, object $context): void
    {
        $eventName = $event instanceof BackedEnum ? (string) $event->value : $event;

        foreach ($this->subscribers as $subscriberClass) {
            resolve($subscriberClass)->handle($eventName, $context);
        }
    }

    public function validateWithSubscribers(string|BackedEnum $event, object $context): bool
    {
        $eventName = $event instanceof BackedEnum ? (string) $event->value : $event;

        foreach ($this->subscribers as $subscriberClass) {
            $subscriber = resolve($subscriberClass);

            if (! $subscriber instanceof ValidatingSubscriber) {
                continue;
            }

            if ($subscriber->validate($eventName, $context) === false) {
                return false;
            }
        }

        return true;
    }

    protected function subscriberContract(): string
    {
        return Subscriber::class;
    }
}
