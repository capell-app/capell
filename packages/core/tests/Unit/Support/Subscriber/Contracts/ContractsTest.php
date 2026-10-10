<?php

declare(strict_types=1);

use Capell\Core\Support\Subscriber\Contracts\Subscriber;
use Capell\Core\Support\Subscriber\Contracts\ValidatingSubscriber;
use Capell\Core\Support\Subscriber\SubscriberManager;

it('delivers events and context through the subscriber contract', function (): void {
    $subscriber = new class implements Subscriber
    {
        #[Override]
        public function handle(string $event, object $context): void
        {
            $context->delivered = $event;
        }
    };
    app()->instance($subscriber::class, $subscriber);
    /** @var SubscriberManager<Subscriber> $manager */
    $manager = new SubscriberManager;
    $manager->subscribe($subscriber::class);

    $context = new stdClass;
    $manager->notifySubscribers('published', $context);
    expect($context->delivered)->toBe('published');
});

it('lets validating subscribers reject a transition and receive events', function (bool $allowed): void {
    $subscriber = new class implements ValidatingSubscriber
    {
        #[Override]
        public function validate(string $event, object $context): bool
        {
            return $event === 'publish' && $context->allowed;
        }

        #[Override]
        public function handle(string $event, object $context): void
        {
            $context->delivered = $event;
        }
    };
    app()->instance($subscriber::class, $subscriber);
    /** @var SubscriberManager<Subscriber> $manager */
    $manager = new SubscriberManager;
    $manager->subscribe($subscriber::class);

    $context = (object) ['allowed' => $allowed];
    expect($manager->validateWithSubscribers('publish', $context))->toBe($allowed);
    $manager->notifySubscribers('publish', $context);
    expect($context->delivered)->toBe('publish');
})->with([true, false]);
