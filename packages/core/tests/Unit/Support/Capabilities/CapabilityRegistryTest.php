<?php

declare(strict_types=1);

use Capell\Core\Contracts\Capabilities\FormCatalogue;
use Capell\Core\Data\Capabilities\CampaignConversionData;
use Capell\Core\Data\Capabilities\FormReferenceData;
use Capell\Core\Data\Capabilities\PublishingWorkspaceTransitionData;
use Capell\Core\Data\Capabilities\SiteAccessRegistrationData;
use Capell\Core\Data\Capabilities\SubmittedFormData;
use Capell\Core\Enums\CapabilityContract;
use Capell\Core\Events\Capabilities\CampaignConversionRecorded;
use Capell\Core\Events\Capabilities\FormSubmissionReceived;
use Capell\Core\Events\Capabilities\PublishingWorkspaceTransitioned;
use Capell\Core\Events\Capabilities\SiteAccessRegistrationApproved;
use Capell\Core\Support\Capabilities\CapabilityRegistry;
use Capell\Core\Support\Capabilities\NullFormCatalogue;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Facades\Event;

final class CapabilityRegistryTestFormCatalogue implements FormCatalogue
{
    public function forms(?int $siteId = null): array
    {
        return [new FormReferenceData(7, 'contact', 'Contact', $siteId, ['email' => 'Email'])];
    }

    public function find(int $formId): ?FormReferenceData
    {
        return $formId === 7 ? $this->forms()[0] : null;
    }
}

it('gives consumers the null catalogue when no package provides forms', function (): void {
    $registry = new CapabilityRegistry;

    expect($registry->get(FormCatalogue::class))->toBeInstanceOf(NullFormCatalogue::class)
        ->and($registry->get(FormCatalogue::class)->forms())->toBe([])
        ->and($registry->get(FormCatalogue::class)->find(7))->toBeNull()
        ->and($registry->isProvided(FormCatalogue::class))->toBeFalse()
        ->and($registry->provided())->toBe([]);
});

it('resolves the providing package through the contract', function (): void {
    $registry = new CapabilityRegistry;
    $registry->provide(FormCatalogue::class, CapabilityRegistryTestFormCatalogue::class);

    expect($registry->get(FormCatalogue::class)->find(7)?->key)->toBe('contact')
        ->and($registry->isProvided(FormCatalogue::class))->toBeTrue()
        ->and($registry->provided())->toBe([CapabilityContract::FormCatalogue]);
});

it('resolves the container binding through the booted registry', function (): void {
    expect(resolve(FormCatalogue::class))->toBeInstanceOf(NullFormCatalogue::class);

    resolve(CapabilityRegistry::class)->provide(FormCatalogue::class, static fn (): FormCatalogue => new CapabilityRegistryTestFormCatalogue);

    expect(resolve(FormCatalogue::class)->forms(3)[0]->siteId)->toBe(3);
});

it('refuses providers that would make the contract ambiguous or untyped', function (Closure $register, string $message): void {
    expect($register)->toThrow(InvalidArgumentException::class, $message);
})->with([
    'a second provider' => [function (): void {
        $registry = new CapabilityRegistry;
        $registry->provide(FormCatalogue::class, CapabilityRegistryTestFormCatalogue::class);
        $registry->provide(FormCatalogue::class, NullFormCatalogue::class);
    }, 'only one package may provide it'],
    'a class that does not implement the contract' => [fn () => new CapabilityRegistry()->provide(FormCatalogue::class, stdClass::class), 'does not implement'],
    'a closure that returns the wrong type' => [function (): void {
        $registry = new CapabilityRegistry;
        $registry->provide(FormCatalogue::class, static fn (): object => new stdClass);
        $registry->get(FormCatalogue::class);
    }, 'does not implement it'],
    'an interface that is not a capability contract' => [fn (): object => new CapabilityRegistry()->get(Countable::class), 'is not a Core capability contract'],
]);

it('carries capability signals as Core events with model-free payloads', function (): void {
    Event::fake();
    $at = CarbonImmutable::parse('2026-11-13 09:00:00');
    $form = new FormReferenceData(7, 'contact', 'Contact', 1);

    event(new FormSubmissionReceived(new SubmittedFormData($form, 11, ['email' => 'a@example.com'], $at)));
    event(new SiteAccessRegistrationApproved(new SiteAccessRegistrationData(5, 'member@example.com', $at, siteId: 1)));
    event(new PublishingWorkspaceTransitioned(new PublishingWorkspaceTransitionData(9, 'review', 'approved', 'approve', $at, actorId: 2)));
    event(new CampaignConversionRecorded(new CampaignConversionData(3, 4, $at, goal: 'signup')));

    Event::assertDispatched(FormSubmissionReceived::class, fn (FormSubmissionReceived $event): bool => $event->submission->values === ['email' => 'a@example.com']
        && unserialize(serialize($event))->submission->form->key === 'contact');
    Event::assertDispatched(SiteAccessRegistrationApproved::class, fn (SiteAccessRegistrationApproved $event): bool => $event->registration->email === 'member@example.com');
    Event::assertDispatched(PublishingWorkspaceTransitioned::class, fn (PublishingWorkspaceTransitioned $event): bool => $event->transition->newState === 'approved');
    Event::assertDispatched(CampaignConversionRecorded::class, fn (CampaignConversionRecorded $event): bool => $event->conversion->goal === 'signup');

    expect(is_a(PublishingWorkspaceTransitioned::class, ShouldDispatchAfterCommit::class, true))->toBeTrue();
});

it('maps every capability to exactly one typed contract or Core event', function (CapabilityContract $capability): void {
    $target = $capability->contract() ?? $capability->event();

    expect(($capability->contract() === null) !== ($capability->event() === null))->toBeTrue()
        ->and(interface_exists((string) $target) || class_exists((string) $target))->toBeTrue()
        ->and($capability->version())->toBe(1);
})->with(CapabilityContract::cases());
