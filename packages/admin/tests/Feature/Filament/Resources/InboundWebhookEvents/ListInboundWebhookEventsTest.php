<?php

declare(strict_types=1);

use Capell\Admin\Enums\ResourceEnum;
use Capell\Admin\Filament\Resources\InboundWebhookEvents\Pages\ListInboundWebhookEvents;
use Capell\Admin\Tests\Fixtures\Webhooks\NoopInboundWebhookHandler;
use Capell\Core\Enums\InboundWebhookEventStatus;
use Capell\Core\Jobs\Webhooks\ProcessInboundWebhookEventJob;
use Capell\Core\Models\InboundWebhookEvent;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(CreatesAdminUser::class);

/** @param  array<string, mixed>  $attributes */
function storedWebhookEvent(array $attributes = []): InboundWebhookEvent
{
    return InboundWebhookEvent::query()->create([
        'provider' => 'payments.stripe',
        'event_id' => 'evt_' . bin2hex(random_bytes(6)),
        'type' => 'checkout.session.completed',
        'subject' => 'cs_test_1',
        'occurred_at' => now(),
        'status' => InboundWebhookEventStatus::Failed,
        'payload' => ['customer_email' => 'buyer@example.test'],
        'payload_hash' => hash('sha256', 'payload'),
        'handler' => NoopInboundWebhookHandler::class,
        'attempts' => 1,
        'last_error' => 'Order lookup timed out.',
        ...$attributes,
    ]);
}

/**
 * @param  list<string>  $affixes
 * @return list<string>
 */
function webhookEventPermissions(array $affixes = ['view_any', 'view', 'update']): array
{
    return array_map(
        static fn (string $affix): string => Permission::findOrCreate(ResourceEnum::InboundWebhookEvent->permission($affix))->name,
        $affixes,
    );
}

beforeEach(function (): void {
    $this->operator = $this->createUser();
});

it('needs webhook event permission to list events', function (): void {
    $this->actingAs($this->createUser());

    Livewire::test(ListInboundWebhookEvents::class)->assertForbidden();
});

it('lists stored events with their status and failure, never their payload', function (): void {
    $this->actingAs($this->operator->givePermissionTo(webhookEventPermissions(['view_any', 'view'])));
    $event = storedWebhookEvent();

    Livewire::test(ListInboundWebhookEvents::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$event])
        ->assertSee('payments.stripe')
        ->assertSee('checkout.session.completed')
        ->assertSee('Order lookup timed out.')
        ->assertDontSee('buyer@example.test')
        ->assertTableActionHidden('replay', $event);
});

it('replays a failed event onto the queue', function (): void {
    Queue::fake();
    $this->actingAs($this->operator->givePermissionTo(webhookEventPermissions()));
    $event = storedWebhookEvent();

    Livewire::test(ListInboundWebhookEvents::class)
        ->callTableAction('replay', $event)
        ->assertNotified(__('capell-admin::inbound_webhooks.replay_outcomes.queued'));

    Queue::assertPushed(ProcessInboundWebhookEventJob::class);
    expect($event->refresh()->status)->toBe(InboundWebhookEventStatus::Received);
});

it('replays a processed event only when the operator asks to apply it again', function (): void {
    Queue::fake();
    $this->actingAs($this->operator->givePermissionTo(webhookEventPermissions()));
    $event = storedWebhookEvent(['status' => InboundWebhookEventStatus::Processed, 'last_error' => null]);

    Livewire::test(ListInboundWebhookEvents::class)
        ->callTableAction('replay', $event, ['force' => false])
        ->assertHasTableActionErrors(['force' => 'accepted']);

    Queue::assertNothingPushed();

    Livewire::test(ListInboundWebhookEvents::class)
        ->callTableAction('replay', $event, ['force' => true])
        ->assertNotified(__('capell-admin::inbound_webhooks.replay_outcomes.queued'));

    Queue::assertPushed(ProcessInboundWebhookEventJob::class);
});

it('offers no replay for an ignored event', function (): void {
    $this->actingAs($this->operator->givePermissionTo(webhookEventPermissions()));
    $event = storedWebhookEvent(['status' => InboundWebhookEventStatus::Ignored, 'handler' => null, 'last_error' => null]);

    Livewire::test(ListInboundWebhookEvents::class)
        ->assertCanSeeTableRecords([$event])
        ->assertTableActionHidden('replay', $event);
});
