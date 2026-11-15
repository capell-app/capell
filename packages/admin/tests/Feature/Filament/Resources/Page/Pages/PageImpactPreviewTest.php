<?php

declare(strict_types=1);

use Capell\Admin\Actions\EditorImpact\BuildRecordImpactPreviewAction;
use Capell\Admin\Contracts\EditorImpact\EditorImpactConsequencePlanner;
use Capell\Admin\Filament\Resources\Pages\Pages\EditPage;
use Capell\Core\Data\EditorImpact\EditorImpactConsequenceData;
use Capell\Core\Enums\ContentGraph\ContentGraphEdgeKind;
use Capell\Core\Enums\ContentGraph\ContentGraphEdgeStrength;
use Capell\Core\Models\ContentGraphEdge;
use Capell\Core\Models\Page;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Database\Eloquent\Model;
use Livewire\Livewire;

uses(CreatesAdminUser::class)->group('page');

it('saves an isolated page straight away without opening the impact confirmation', function (): void {
    test()->actingAsAdmin();
    $page = Page::factory()->withTranslations()->createOne();

    Livewire::test(EditPage::class, ['record' => $page->getRouteKey()])
        ->assertActionDoesNotExist('impact-preview')
        ->callAction('save')
        ->assertHasNoFormErrors()
        ->assertActionNotMounted('save')
        ->assertDispatched('page-editor-saved', pageId: (int) $page->getKey());
});

it('asks for confirmation with the contributed consequences when saving affects other content', function (): void {
    test()->actingAsAdmin();
    $page = Page::factory()->withTranslations()->createOne();
    $planner = new class implements EditorImpactConsequencePlanner
    {
        public int $calls = 0;

        public function plan(Model $record): array
        {
            $this->calls++;

            return [new EditorImpactConsequenceData(
                label: 'Cache entries',
                description: 'Recorded dependencies',
                count: 3,
                urls: ['https://example.test/a', 'https://example.test/b', 'https://example.test/c'],
                estimatedSeconds: 3 * 0.25,
                estimateBasis: 'Measured fixture: 0.25 seconds per URL',
            )];
        }
    };
    app()->instance($planner::class, $planner);
    app()->tag($planner::class, EditorImpactConsequencePlanner::TAG);

    $linkingPage = Page::factory()->withTranslations()->createOne(['name' => 'Linking page']);
    ContentGraphEdge::query()->create([
        'source_type' => Page::class,
        'source_id' => $linkingPage->id,
        'target_type' => Page::class,
        'target_id' => $page->id,
        'kind' => ContentGraphEdgeKind::LinksToPage,
        'strength' => ContentGraphEdgeStrength::Weak,
        'source_package' => 'capell-app/core',
    ]);

    $editor = Livewire::test(EditPage::class, ['record' => $page->getRouteKey()]);

    $editor->mountAction('save')
        ->assertActionMounted('save')
        ->call('forceRender')
        ->assertSee(__('capell-admin::impact.heading'))
        ->assertSee('Linking page')
        ->assertSee('Cache entries: 3')
        ->assertSee('https://example.test/a')
        ->assertSee(__('capell-admin::impact.estimated_seconds', ['seconds' => '0.75']))
        ->callMountedAction()
        ->assertHasNoFormErrors()
        ->assertDispatched('page-editor-saved', pageId: (int) $page->getKey());
});

it('does not let informational costs change save fingerprints and denies guests', function (): void {
    test()->actingAsAdmin();
    $page = Page::factory()->createOne();
    $preview = BuildRecordImpactPreviewAction::run($page);
    expect($preview)->not->toBeNull();
    $payload = $preview->planPayload();
    $fingerprint = $preview->fingerprint;
    $preview->consequences = [new EditorImpactConsequenceData('Cache', 'Changed timing', 3, estimatedSeconds: 99)];
    expect($preview->planPayload())->toBe($payload)
        ->and($preview->fingerprint)->toBe($fingerprint);

    auth()->logout();
    expect(BuildRecordImpactPreviewAction::run($page))->toBeNull();
});
