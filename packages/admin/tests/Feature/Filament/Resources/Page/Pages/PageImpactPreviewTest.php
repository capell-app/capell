<?php

declare(strict_types=1);

use Capell\Admin\Actions\EditorImpact\BuildRecordImpactPreviewAction;
use Capell\Admin\Contracts\EditorImpact\EditorImpactConsequencePlanner;
use Capell\Admin\Filament\Resources\Pages\Pages\EditPage;
use Capell\Core\Data\EditorImpact\EditorImpactConsequenceData;
use Capell\Core\Models\Page;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Database\Eloquent\Model;
use Livewire\Livewire;

uses(CreatesAdminUser::class)->group('page');

it('renders contributed consequences on demand in the page impact preview', function (): void {
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

    $editor = Livewire::test(EditPage::class, ['record' => $page->getRouteKey()]);
    expect($planner->calls)->toBe(0);

    $editor->assertActionExists('impact-preview');
    $editor->mountAction('impact-preview')
        ->assertActionMounted('impact-preview')
        ->call('forceRender')
        ->assertSee('Cache entries: 3')
        ->assertSee('https://example.test/a')
        ->assertSee('https://example.test/b')
        ->assertSee('https://example.test/c')
        ->assertSee(__('capell-admin::impact.estimated_seconds', ['seconds' => '0.75']));

    expect($planner->calls)->toBeGreaterThan(0);
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
