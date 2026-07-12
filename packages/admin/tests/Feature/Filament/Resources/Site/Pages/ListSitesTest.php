<?php

declare(strict_types=1);

use Capell\Admin\Filament\Actions\Table\ReplicateSiteAction;
use Capell\Admin\Filament\Resources\Sites\Pages\ListSites;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Livewire\Livewire;

use function Pest\Laravel\assertModelExists;
use function Pest\Laravel\assertSoftDeleted;

uses(CreatesAdminUser::class)
    ->group('site');

beforeEach(function (): void {
    test()->actingAsAdmin();
    Blueprint::factory()->site()->default()->create();
});

test('can list sites', function (): void {
    $sites = Site::factory()->count(5)->create();

    Livewire::test(ListSites::class)
        ->assertSuccessful()
        ->assertCountTableRecords(5)
        ->assertSee(__('capell-admin::table.site_type'))
        ->assertCanSeeTableRecords($sites);
});

test('can list a site when its theme has been deleted', function (): void {
    $site = Site::factory()->createOne();
    $site->theme->delete();

    Livewire::test(ListSites::class)
        ->assertSuccessful()
        ->toggleAllTableColumns()
        ->assertCanSeeTableRecords([$site]);
});

test('escapes site translation previews before rendering content column', function (): void {
    Site::factory()
        ->withTranslations(data: [
            'title' => 'Title <script>alert(1)</script>',
            'content' => 'Content <script>alert(2)</script>',
        ])
        ->create();

    Livewire::test(ListSites::class)
        ->assertSuccessful()
        ->toggleAllTableColumns()
        ->assertDontSeeHtml('<script>alert(1)</script>')
        ->assertDontSeeHtml('<script>alert(2)</script>');
});

test('can search sites', function (): void {
    $sites = Site::factory()
        ->sequence(fn (Sequence $sequence): array => ['name' => sprintf('Language(%d)', $sequence->index)])
        ->count(3)
        ->create();

    $name = $sites->random()->name;

    Livewire::test(ListSites::class)
        ->assertSuccessful()
        ->assertCountTableRecords(3)
        ->searchTable($name)
        ->assertCountTableRecords(1)
        ->assertCanSeeTableRecords($sites->where('name', $name))
        ->assertCanNotSeeTableRecords($sites->where('name', '!=', $name));
});

test('can sort sites', function (): void {
    $sites = Site::factory()->count(5)->create();

    Livewire::test(ListSites::class)
        ->assertSuccessful()
        ->assertCountTableRecords(5)
        ->sortTable('name')
        ->assertCanSeeTableRecords($sites->sortBy('name'), inOrder: true);
});

test('can replicate site', function (): void {
    $site = Site::factory()->createOne();

    $language = Language::factory()->createOne();

    $name = fake()->unique()->name();

    Livewire::test(ListSites::class)
        ->assertSuccessful()
        ->assertCountTableRecords(1)
        ->callAction(
            TestAction::make(ReplicateSiteAction::class)->table($site),
            data: [
                'name' => $name,
                'language_id' => $site->language_id,
                'languages' => [$language->getKey()],
            ],
        )
        ->goToNextWizardStep()
        ->assertHasNoFormErrors()
        ->fillForm([
            'site_domains' => [
                [
                    'domain' => 'http://localhost',
                    'language_id' => $site->language_id,
                ],
            ],
        ])
        ->callMountedAction()
        ->assertHasNoFormErrors()
        ->assertCountTableRecords(2);

    expect(Site::query()->count())->toBe(2)
        ->and(Site::query()->firstWhere('name', $name))
        ->toBeInstanceOf(Site::class)
        ->name->toBe($name)
        ->language_id->toBe($site->language_id)
        ->siteDomains->count()->toBe(2)
        ->siteDomains->first()
        ->domain->toBe('localhost')
        ->language_id->toBe($site->language_id);
});

test('groups related theme and blueprint editing actions for sites', function (): void {
    $theme = Theme::factory()->createOne(['name' => 'Site theme']);
    $blueprint = Blueprint::factory()->site()->createOne(['name' => 'Site blueprint']);
    $site = Site::factory()->theme($theme)->blueprint($blueprint)->createOne();

    $component = Livewire::test(ListSites::class)
        ->assertSuccessful()
        ->mountTableAction('edit-theme', $site)
        ->assertMountedActionModalSee(__('capell-admin::button.edit_theme'));

    Livewire::test(ListSites::class)
        ->assertSuccessful()
        ->mountTableAction('edit-blueprint', $site)
        ->assertMountedActionModalSee(__('capell-admin::button.edit_blueprint'));

    $recordActions = $component->instance()->getTable()->getRecordActions();

    expect($recordActions[0]->getName())->toBe('edit')
        ->and($recordActions[1])->toBeInstanceOf(ActionGroup::class);

    assert($recordActions[1] instanceof ActionGroup);

    expect(array_keys($recordActions[1]->getFlatActions()))
        ->toContain('edit-theme', 'edit-blueprint');
});

test('can delete site', function (): void {
    $site = Site::factory()->createOne();

    Livewire::test(ListSites::class)
        ->assertSuccessful()
        ->assertCountTableRecords(1)
        ->callAction(TestAction::make(DeleteAction::class)->table($site))
        ->assertHasNoFormErrors()
        ->assertCountTableRecords(0);

    assertSoftDeleted($site, ['id' => $site->id]);
});

test('site delete modal warns about affected pages', function (): void {
    $site = Site::factory()->withTranslations()->create();
    $layout = Layout::factory()->createOne(['site_id' => $site->getKey()]);
    Page::factory()->site($site)->layout($layout)->withTranslations()->create();

    Livewire::test(ListSites::class)
        ->assertSuccessful()
        ->mountAction(TestAction::make(DeleteAction::class)->table($site))
        ->assertMountedActionModalSee('1 page');
});

test('bulk site delete modal warns about aggregate affected pages', function (): void {
    $sites = Site::factory()->count(2)->withTranslations()->create();

    $sites->each(function (Site $site): void {
        $layout = Layout::factory()->createOne(['site_id' => $site->getKey()]);

        Page::factory()->site($site)->layout($layout)->withTranslations()->create();
    });

    Livewire::test(ListSites::class)
        ->assertSuccessful()
        ->selectTableRecords($sites)
        ->mountAction(TestAction::make(DeleteBulkAction::class)->table()->bulk())
        ->assertMountedActionModalSee('2 pages');
});

test('site table delete action cascades through site-owned records', function (): void {
    $site = Site::factory()->withTranslations()->create();
    $layout = Layout::factory()->createOne(['site_id' => $site->getKey()]);
    $page = Page::factory()->site($site)->layout($layout)->withTranslations()->create();
    $pageUrl = PageUrl::factory()
        ->site($site)
        ->page($page)
        ->state(['language_id' => $site->language_id])
        ->create();

    Livewire::test(ListSites::class)
        ->assertSuccessful()
        ->callAction(TestAction::make(DeleteAction::class)->table($site))
        ->assertHasNoFormErrors();

    assertSoftDeleted($site);
    assertSoftDeleted($layout);
    assertSoftDeleted($page);
    assertSoftDeleted($pageUrl);
});

test('can group delete sites', function (): void {
    $site = Site::factory()->default()->create();
    $sites = Site::factory()->count(5)->create();

    Livewire::test(ListSites::class)
        ->assertSuccessful()
        ->selectTableRecords($sites)
        ->callAction(TestAction::make(DeleteBulkAction::class)->table()->bulk())
        ->assertHasNoFormErrors();

    assertModelExists($site);

    foreach ($sites as $deletedSite) {
        assertSoftDeleted($deletedSite, ['id' => $deletedSite->id]);
    }
});
