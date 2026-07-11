# Event Registry Hook System

![Capell Event Registry Hook System screenshot](./images/screenshots/admin-dashboard.png)

The Capell Admin package provides an event registry hook system that follows the Observer pattern, allowing other packages to subscribe to and receive events from specific classes.

## Usage

### Using Callbacks

You can register handlers for specific Livewire events and classes using `AdminEventRegistry`:

```php
use Capell\Admin\Filament\Resources\Pages\Pages\EditPage;
use Capell\Admin\Support\AdminEventHandlerInterface;
use Capell\Admin\Support\AdminEventRegistry;
use Livewire\Component;

final class RefreshPreviewHandler implements AdminEventHandlerInterface
{
    public function handle(array $payload, Component $component): void
    {
        if ($component instanceof EditPage) {
            $component->dispatch('refresh-preview');
        }
    }
}

resolve(AdminEventRegistry::class)->register(
    EditPage::class,
    'refreshPreview',
    RefreshPreviewHandler::class,
);
```

This ensures that the handler only runs when the registered event is routed from an instance of the specified class.

### Using Subscribers (Observer Pattern)

For more complex event handling, you can create a subscriber class that implements the `EventSubscriber` interface:

For validation events that need to return a boolean value, implement the `ValidationSubscriber` interface:

```php
use Capell\Admin\Contracts\ValidationSubscriber;
use Capell\Core\Contracts\EventSubscriber;
use Capell\Core\Models\Blueprint;

class MyEventSubscriber implements EventSubscriber
{
    /**
     * Handle the event.
     *
     * @param string $event The event name
     * @param object $context The context object
     * @return void
     */
    public function handle(string $event, object $context): void
    {
        if ($event === 'afterSave' && $context instanceof EditPage) {
            // Handle the afterSave event for EditPage
            $context->dispatch('refresh-preview');
        }
    }
}

// For validation events that need to return a boolean value

class MyValidationSubscriber implements ValidationSubscriber
{
    /**
     * Handle the event.
     *
     * @param string $event The event name
     * @param object $context The context object
     * @return void
     */
    public function handle(string $event, object $context): void
    {
        // Handle regular events
    }

    /**
     * Validate the event.
     *
     * @param string $event The event name
     * @param object $context The context object
     * @return bool Returns false if validation fails, true otherwise
     */
    public function validate(string $event, object $context): bool
    {
        if ($event === 'validateCustomType' && $context instanceof Blueprint) {
            // Validate if the custom type can be deleted
            // Return false to prevent deletion, true to allow it
            if ($context->name === 'custom_type' && $this->hasRelatedRecords($context)) {
                // Add error message or notification if needed
                return false;
            }
        }

        return true;
    }

    private function hasRelatedRecords(Blueprint $type): bool
    {
        // Custom logic to check if the type has related records
        return true; // or false
    }
}
```

Then, subscribe to events through Core's subscriber manager:

```php
use Capell\Core\Facades\CapellCore;

CapellCore::subscriberManager()->subscribe(MyEventSubscriber::class);
```

You can also unsubscribe when needed:

```php
CapellCore::subscriberManager()->unsubscribe(MyEventSubscriber::class);
```

## Available Events

- `afterSave`: Executed after a page is saved in the EditPage class.
- `validateCustomType`: Executed when validating if a type can be deleted in BlueprintValidation trait.

## Adding New Events

To add a new event, you need to:

1. Add a call to `SubscriberManager::notifySubscribers()` in the appropriate place in your code:

```php
use Capell\Core\Support\Subscriber\SubscriberManager;

resolve(SubscriberManager::class)->notifySubscribers('eventName', $context);
```

2. Document the new event in this README.
