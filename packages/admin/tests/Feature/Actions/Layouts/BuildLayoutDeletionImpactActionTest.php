<?php

declare(strict_types=1);

use Capell\Admin\Actions\Layouts\BuildLayoutDeletionImpactAction;
use Capell\Admin\Support\Layouts\LayoutCardData;
use Capell\Admin\Tests\Support\ScopedAdminUser;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Tests\Support\Concerns\CreatesAdminUser;

uses(CreatesAdminUser::class);

it('uses the selected aggregate when it is already available', function (): void {
    test()->actingAsAdmin();

    $layout = Layout::factory()->createOne();
    $layout->setAttribute('pages_count', 3);

    $impact = BuildLayoutDeletionImpactAction::run($layout);

    expect($impact->knownReferenceCount)->toBe(3)
        ->and($impact->authoritative)->toBeTrue()
        ->and($impact->affectedLabel)->toBe('3 known pages')
        ->and($impact->referencesUrl)->not->toBeNull();
});

it('does not claim actor-scoped usage is globally authoritative when no aggregate is loaded', function (): void {
    $layout = Layout::factory()->createOne();
    $assignedSite = Site::factory()->createOne();
    $hiddenSite = Site::factory()->createOne();

    Page::factory()->count(2)->site($assignedSite)->layout($layout)->create();
    Page::factory()->count(3)->site($hiddenSite)->layout($layout)->create();

    test()->actingAs(ScopedAdminUser::make(collect([$assignedSite->getKey()])));

    $impact = BuildLayoutDeletionImpactAction::run($layout);

    expect($impact->knownReferenceCount)->toBe(2)
        ->and($impact->authoritative)->toBeFalse()
        ->and($impact->affectedLabel)->toBe('2 known pages');
});

it('does not mark a layout as unused when hidden-site usage is outside the actor scope', function (): void {
    $layout = Layout::factory()->createOne(['status' => true]);
    $assignedSite = Site::factory()->createOne();
    $hiddenSite = Site::factory()->createOne();
    Page::factory()->site($hiddenSite)->layout($layout)->createOne();

    test()->actingAs(ScopedAdminUser::make(collect([$assignedSite->getKey()])));

    $impact = BuildLayoutDeletionImpactAction::run($layout);
    $card = LayoutCardData::fromLayout($layout);
    $html = view('capell-admin::components.record-deletion-impact', ['impact' => $impact])->render();

    expect($impact->knownReferenceCount)->toBe(0)
        ->and($impact->authoritative)->toBeFalse()
        ->and(collect($card->states())->pluck('key')->all())->not->toContain('unused')
        ->and($html)->toContain('No tracked uses')
        ->not->toContain('Unused');
});
