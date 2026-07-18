<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Unit\Support\Subscriber;

use Capell\Core\Contracts\EventSubscriber;
use Capell\Core\Support\Subscriber\Contracts\Subscriber;
use Capell\Core\Support\Subscriber\Contracts\ValidatingSubscriber;
use Capell\Core\Support\Subscriber\SubscriberRegistry;
use Capell\Core\Tests\Unit\Support\Subscriber\Fixtures\SubscriberRegistryTestEvent;
use InvalidArgumentException;
use stdClass;

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

/**
 * Shared recorder used by anonymous-class subscribers (instantiated by the
 * manager via `new $subscriber` with no constructor arguments) to report
 * what they observed. Each subscriber FQCN identifies its own source label
 * via the $labels map below.
 */
function subscriberManagerRecorder(): SubscriberRegistryRecorder
{
    static $recorder = null;

    if ($recorder === null) {
        $recorder = new SubscriberRegistryRecorder;
    }

    return $recorder;
}

beforeEach(function (): void {
    $recorder = subscriberManagerRecorder();
    $recorder->handled = [];
    $recorder->validated = [];
    $recorder->labels = [];
    $recorder->validateReturns = [];
});

it('notifies subscribers in subscription order with the resolved event name', function (): void {
    $firstSubscriberClass = (new class implements EventSubscriber
    {
        public function handle(string $event, object $context): void
        {
            subscriberManagerRecorder()->handled[] = [
                'event' => $event,
                'context' => $context,
                'source' => 'first',
            ];
        }
    })::class;

    $secondSubscriberClass = (new class implements EventSubscriber
    {
        public function handle(string $event, object $context): void
        {
            subscriberManagerRecorder()->handled[] = [
                'event' => $event,
                'context' => $context,
                'source' => 'second',
            ];
        }
    })::class;

    $manager = new SubscriberRegistry;
    $manager->subscribe($firstSubscriberClass);
    $manager->subscribe($secondSubscriberClass);

    $eventContext = (object) ['payload' => 'value'];
    $manager->notifySubscribers('page.saved', $eventContext);

    $handled = subscriberManagerRecorder()->handled;
    expect($handled)->toHaveCount(2);
    expect($handled[0]['source'])->toBe('first');
    expect($handled[0]['event'])->toBe('page.saved');
    expect($handled[0]['context'])->toBe($eventContext);
    expect($handled[1]['source'])->toBe('second');
});

it('unwraps backed enum events to their string value when notifying', function (): void {
    $subscriberClass = (new class implements EventSubscriber
    {
        public function handle(string $event, object $context): void
        {
            subscriberManagerRecorder()->handled[] = [
                'event' => $event,
                'context' => $context,
                'source' => 'enum-test',
            ];
        }
    })::class;

    $manager = new SubscriberRegistry;
    $manager->subscribe($subscriberClass);
    $manager->notifySubscribers(SubscriberRegistryTestEvent::Published, (object) []);

    $handled = subscriberManagerRecorder()->handled;
    expect($handled)->toHaveCount(1);
    expect($handled[0]['event'])->toBe('page.published');
});

it('does nothing when notifying with no subscribers registered', function (): void {
    $manager = new SubscriberRegistry;
    $manager->notifySubscribers('page.saved', (object) []);

    expect(subscriberManagerRecorder()->handled)->toBe([]);
});

it('returns true from validateWithSubscribers when every subscriber accepts', function (): void {
    $alphaSubscriberClass = (new class implements EventSubscriber, ValidatingSubscriber
    {
        public function handle(string $event, object $context): void {}

        public function validate(string $event, object $context): bool
        {
            subscriberManagerRecorder()->validated[] = [
                'event' => $event,
                'context' => $context,
                'source' => 'alpha',
            ];

            return true;
        }
    })::class;

    $betaSubscriberClass = (new class implements EventSubscriber, ValidatingSubscriber
    {
        public function handle(string $event, object $context): void {}

        public function validate(string $event, object $context): bool
        {
            subscriberManagerRecorder()->validated[] = [
                'event' => $event,
                'context' => $context,
                'source' => 'beta',
            ];

            return true;
        }
    })::class;

    $manager = new SubscriberRegistry;
    $manager->subscribe($alphaSubscriberClass);
    $manager->subscribe($betaSubscriberClass);

    expect($manager->validateWithSubscribers('page.saving', (object) []))->toBeTrue();
    expect(subscriberManagerRecorder()->validated)->toHaveCount(2);
});

it('unsubscribes by class-string', function (): void {
    $firstSubscriberClass = (new class implements EventSubscriber
    {
        public function handle(string $event, object $context): void
        {
            subscriberManagerRecorder()->handled[] = [
                'event' => $event,
                'context' => $context,
                'source' => 'first',
            ];
        }
    })::class;

    $manager = new SubscriberRegistry;
    $manager->subscribe($firstSubscriberClass);
    $manager->unsubscribe($firstSubscriberClass);

    $manager->notifySubscribers('any.event', (object) []);

    expect(subscriberManagerRecorder()->handled)->toBe([]);
});

it('subscribing the same class twice yields one entry', function (): void {
    $firstSubscriberClass = (new class implements EventSubscriber
    {
        public function handle(string $event, object $context): void {}
    })::class;

    $manager = new SubscriberRegistry;
    $manager->subscribe($firstSubscriberClass);
    $manager->subscribe($firstSubscriberClass);

    expect($manager->getSubscribers())->toBe([$firstSubscriberClass]);
});

it('exposes hasSubscriber for membership checks', function (): void {
    $firstSubscriberClass = (new class implements EventSubscriber
    {
        public function handle(string $event, object $context): void {}
    })::class;

    $manager = new SubscriberRegistry;
    $manager->subscribe($firstSubscriberClass);

    expect($manager->hasSubscriber($firstSubscriberClass))->toBeTrue()
        ->and($manager->hasSubscriber(stdClass::class))->toBeFalse();
});

it('short-circuits validateWithSubscribers as soon as one subscriber returns false', function (): void {
    $firstSubscriberClass = (new class implements EventSubscriber, ValidatingSubscriber
    {
        public function handle(string $event, object $context): void {}

        public function validate(string $event, object $context): bool
        {
            subscriberManagerRecorder()->validated[] = [
                'event' => $event,
                'context' => $context,
                'source' => 'first',
            ];

            return true;
        }
    })::class;

    $blockingSubscriberClass = (new class implements EventSubscriber, ValidatingSubscriber
    {
        public function handle(string $event, object $context): void {}

        public function validate(string $event, object $context): bool
        {
            subscriberManagerRecorder()->validated[] = [
                'event' => $event,
                'context' => $context,
                'source' => 'blocker',
            ];

            return false;
        }
    })::class;

    $thirdSubscriberClass = (new class implements EventSubscriber, ValidatingSubscriber
    {
        public function handle(string $event, object $context): void {}

        public function validate(string $event, object $context): bool
        {
            subscriberManagerRecorder()->validated[] = [
                'event' => $event,
                'context' => $context,
                'source' => 'third',
            ];

            return true;
        }
    })::class;

    $manager = new SubscriberRegistry;
    $manager->subscribe($firstSubscriberClass);
    $manager->subscribe($blockingSubscriberClass);
    $manager->subscribe($thirdSubscriberClass);

    expect($manager->validateWithSubscribers('page.saving', (object) []))->toBeFalse();

    $sources = array_column(subscriberManagerRecorder()->validated, 'source');
    expect($sources)->toBe(['first', 'blocker']);
});

it('resolves subscribers via the container so they can use constructor DI', function (): void {
    $captured = new stdClass;
    $captured->value = null;

    app()->instance('subscriber.dep', $captured);

    // Instantiate with a dummy dependency just to obtain the anonymous class name.
    $dummy = new stdClass;
    $subscriberInstance = new readonly class($dummy) implements Subscriber
    {
        public function __construct(private stdClass $dependency) {}

        public function handle(string $event, object $context): void
        {
            $this->dependency->value = $event;
        }
    };
    $subscriberClassName = $subscriberInstance::class;

    app()->bind($subscriberClassName, fn (): object => new $subscriberClassName(resolve('subscriber.dep')));

    $manager = new SubscriberRegistry;
    $manager->subscribe($subscriberClassName);
    $manager->notifySubscribers('event.fired', new stdClass);

    expect($captured->value)->toBe('event.fired');
});

it('validateWithSubscribers ignores plain Subscriber instances and consults only ValidatingSubscriber implementers', function (): void {
    $manager = new SubscriberRegistry;

    $plain = new class implements Subscriber
    {
        public bool $called = false;

        public function handle(string $event, object $context): void
        {
            $this->called = true;
        }
    };

    $validating = new class implements ValidatingSubscriber
    {
        public bool $validateCalled = false;

        public function handle(string $event, object $context): void {}

        public function validate(string $event, object $context): bool
        {
            $this->validateCalled = true;

            return true;
        }
    };

    app()->instance($plain::class, $plain);
    app()->instance($validating::class, $validating);

    $manager->subscribe($plain::class);
    $manager->subscribe($validating::class);

    expect($manager->validateWithSubscribers('event.fired', new stdClass))->toBeTrue();
    expect($plain->called)->toBeFalse();
    expect($validating->validateCalled)->toBeTrue();
});

it('subscribe rejects classes that do not implement the configured contract', function (): void {
    $manager = new class extends SubscriberRegistry
    {
        protected function subscriberContract(): string
        {
            return ValidatingSubscriber::class;
        }
    };

    $plain = new class implements Subscriber
    {
        public function handle(string $event, object $context): void {}
    };

    $manager->subscribe($plain::class);
})->throws(InvalidArgumentException::class);

it('default subscriberContract is Subscriber and accepts any Subscriber implementation', function (): void {
    $manager = new SubscriberRegistry;

    $plain = new class implements Subscriber
    {
        public function handle(string $event, object $context): void {}
    };

    $manager->subscribe($plain::class);

    expect($manager->hasSubscriber($plain::class))->toBeTrue();
});
