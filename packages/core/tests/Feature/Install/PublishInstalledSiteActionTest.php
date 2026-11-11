<?php

declare(strict_types=1);

use Capell\Core\Actions\Install\PublishInstalledSiteAction;
use Capell\Core\Contracts\Publishing\AuthorizesPublicationTransition;
use Capell\Core\Data\Publishing\PublicationTransitionRequestData;
use Capell\Core\Enums\PublishVisibilityStateEnum;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Support\Publishing\PublishSentinel;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Auth\User;

function bindPublicationAuthorizer(bool $allowed): void
{
    app()->instance(AuthorizesPublicationTransition::class, new readonly class($allowed) implements AuthorizesPublicationTransition
    {
        public function __construct(private bool $allowed) {}

        public function allows(PublicationTransitionRequestData $request): bool
        {
            return $this->allowed;
        }
    });
}

function draftPageOn(Site $site, string $name): Page
{
    return Page::factory()->site($site)->create([
        'name' => $name,
        'visible_from' => PublishSentinel::draftValue(),
        'visible_until' => null,
    ]);
}

it('publishes every draft page of the installed site and nothing else', function (): void {
    bindPublicationAuthorizer(true);
    $site = Site::factory()->default()->create();
    $otherSite = Site::factory()->create(['default' => false]);
    $draft = draftPageOn($site, 'About');
    $secondDraft = draftPageOn($site, 'Contact');
    $live = Page::factory()->site($site)->create(['visible_from' => now()->subDay(), 'visible_until' => null]);
    $scheduled = Page::factory()->site($site)->create(['visible_from' => now()->addDays(3), 'visible_until' => null]);
    $foreignDraft = draftPageOn($otherSite, 'Elsewhere');

    $result = PublishInstalledSiteAction::run(new User);

    expect($result->published)->toBe(2)
        ->and($result->refused)->toBe([])
        ->and($draft->refresh()->publishVisibilityState())->toBe(PublishVisibilityStateEnum::published)
        ->and($secondDraft->refresh()->publishVisibilityState())->toBe(PublishVisibilityStateEnum::published)
        ->and($live->refresh()->publishVisibilityState())->toBe(PublishVisibilityStateEnum::published)
        ->and($scheduled->refresh()->publishVisibilityState())->toBe(PublishVisibilityStateEnum::scheduled)
        ->and($foreignDraft->refresh()->publishVisibilityState())->toBe(PublishVisibilityStateEnum::draft);
});

it('is safe to replay: a second run finds nothing left to publish', function (): void {
    bindPublicationAuthorizer(true);
    $site = Site::factory()->default()->create();
    draftPageOn($site, 'About');

    $first = PublishInstalledSiteAction::run(new User);
    $second = PublishInstalledSiteAction::run(new User);

    expect($first->published)->toBe(1)
        ->and($second->published)->toBe(0)
        ->and($second->refused)->toBe([]);
});

it('stops and names the pages it could not publish, leaving them draft', function (): void {
    bindPublicationAuthorizer(false);
    $site = Site::factory()->default()->create();
    $page = draftPageOn($site, 'Pricing');
    $moment = CarbonImmutable::now();

    expect(fn (): mixed => PublishInstalledSiteAction::run(new User))
        ->toThrow(RuntimeException::class, 'Pricing')
        ->and($page->refresh()->publishVisibilityState($moment))->toBe(PublishVisibilityStateEnum::draft);
});

it('does nothing when there is no site yet', function (): void {
    Site::query()->delete();

    expect(PublishInstalledSiteAction::run(new User)->published)->toBe(0);
});
