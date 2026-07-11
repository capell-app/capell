<?php

declare(strict_types=1);

use Capell\Admin\Filament\Actions\CreateAction;
use Capell\Admin\Filament\Components\Tables\Actions\ReplicateAction;
use Capell\Admin\Filament\Resources\Languages\LanguageResource;
use Capell\Admin\Filament\Resources\Languages\Pages\ManageLanguages;
use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\Testing\TestAction;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Collection as SupportCollection;
use Livewire\Livewire;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;
use function Pest\Laravel\assertSoftDeleted;

uses(CreatesAdminUser::class)
    ->group('language');

beforeEach(function (): void {
    test()->actingAsAdmin();
});

/** @param SupportCollection<int, int> $assignedSiteIds */
function createScopedUserForManageLanguagesTest(SupportCollection $assignedSiteIds): Authenticatable
{
    $user = new class extends Authenticatable implements FilamentUser
    {
        /** @use HasFactory<Factory<static>> */
        use HasFactory;

        /** @var SupportCollection<int, int> */
        public SupportCollection $assignedSiteIds;

        protected $table = 'users';

        public function canAccessPanel(Panel $panel): bool
        {
            return true;
        }

        public function isGlobalAdmin(): bool
        {
            return false;
        }

        public function hasRole(string $role): bool
        {
            return true;
        }

        /** @return SupportCollection<int, int> */
        public function getAssignedSiteIds(): SupportCollection
        {
            return $this->assignedSiteIds;
        }
    };

    $user->forceFill([
        'name' => 'Scoped User',
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('password'),
    ]);
    $user->assignedSiteIds = $assignedSiteIds;

    return $user;
}

test('can list languages', function (): void {
    $languages = Language::factory()->count(5)->create();

    Livewire::test(ManageLanguages::class)
        ->assertSuccessful()
        ->assertCountTableRecords($languages->count())
        ->assertCanSeeTableRecords($languages);
});

test('has documentation-aligned subheading', function (): void {
    Livewire::test(ManageLanguages::class)
        ->assertSuccessful();

    expect(resolve(ManageLanguages::class)->getSubheading())
        ->toBe(__('capell-admin::generic.language_info'));
});

test('create language form exposes ordering guidance', function (): void {
    Livewire::test(ManageLanguages::class)
        ->assertSuccessful()
        ->mountAction('create')
        ->assertSchemaComponentVisible('order');

    expect(__('capell-admin::generic.language_order_info'))
        ->not->toBe('capell-admin::generic.language_order_info');
});

test('can search languages', function (): void {
    $languages = Language::factory()
        ->count(3)
        ->sequence(fn (Sequence $sequence): array => ['name' => sprintf('Language(%d)', $sequence->index)])
        ->create();

    $name = $languages->random()->name;

    Livewire::test(ManageLanguages::class)
        ->assertSuccessful()
        ->assertCountTableRecords(3)
        ->searchTable($name)
        ->assertCanSeeTableRecords($languages->where('name', $name))
        ->assertCanNotSeeTableRecords($languages->where('name', '!=', $name));
});

test('can sort languages', function (): void {
    $languages = Language::factory()->count(10)->create();

    $sorted = Language::query()->orderBy('name')->pluck('id');

    Livewire::test(ManageLanguages::class)
        ->assertSuccessful()
        ->assertCountTableRecords($languages->count())
        ->sortTable('name')
        ->assertCanSeeTableRecords($sorted, inOrder: true);
});

test('can replicate language', function (): void {
    $language = Language::factory()->createOne();

    Livewire::test(ManageLanguages::class)
        ->assertSuccessful()
        ->assertCountTableRecords(1)
        ->callAction(
            TestAction::make(ReplicateAction::class)->table($language),
            data: [
                'name' => $language->name . ' (copy)',
                'code' => $language->code . '-copy',
                'locale' => $language->locale . '-copy',
                'flag' => $language->flag,
                'order' => $language->order,
            ],
        );

    assertDatabaseHas('languages', [
        'name' => $language->name . ' (copy)',
        'code' => $language->code . '-copy',
        'locale' => $language->locale . '-copy',
        'flag' => $language->flag,
        'order' => $language->order,
    ]);
});

test('can create language', function (): void {
    $language = Language::factory()->make();

    Livewire::test(ManageLanguages::class)
        ->assertSuccessful()
        ->assertCountTableRecords(0)
        ->callAction(
            CreateAction::class,
            data: [
                'name' => $language->name,
                'code' => $language->code,
                'locale' => $language->locale,
                'flag' => $language->flag,
                'order' => $language->order,
            ],
        )
        ->assertCountTableRecords(1);

    assertDatabaseHas('languages', [
        'name' => $language->name,
        'code' => $language->code,
        'locale' => $language->locale,
        'flag' => $language->flag,
        'order' => $language->order,
    ]);
});

test('can create language and setup for site', function (): void {
    $language = Language::factory()->createOne();

    $site = Site::factory()
        ->state(['language_id' => $language->id])
        ->has(SiteDomain::factory()->state(['language_id' => $language->id]))
        ->default()
        ->create();

    // Generate a unique code ≤12 chars (e.g. 'test' + 8 hex chars = 12)
    do {
        $uniqueCode = 'test' . bin2hex(random_bytes(4));
    } while (strlen($uniqueCode) > 12 || Language::query()->where('code', $uniqueCode)->exists());

    $languageData = Language::factory()->make([
        'code' => $uniqueCode,
    ]);

    Livewire::test(ManageLanguages::class)
        ->assertSuccessful()
        ->assertCountTableRecords(1)
        ->mountAction('create')
        ->fillForm([
            'name' => $languageData->name,
            'code' => $languageData->code,
            'locale' => $languageData->locale,
            'flag' => $languageData->flag,
            'order' => $languageData->order,
            'setup' => true,
            'setup_sites' => [$site->id],
        ])
        ->callMountedAction();

    $newLanguage = expectPresent(Language::query()->firstWhere('code', $languageData->code));

    expect($newLanguage)
        ->name->toBe($languageData->name)
        ->code->toBe($languageData->code)
        ->locale->toBe($languageData->locale)
        ->flag->toBe($languageData->flag)
        ->order->toBe($languageData->order);

    // Assert site has language
    assertDatabaseHas('translations', [
        'translatable_type' => 'site',
        'translatable_id' => $site->id,
        'language_id' => $newLanguage->id,
    ]);

    assertDatabaseHas('site_domains', [
        'site_id' => $site->id,
        'language_id' => $newLanguage->id,
    ]);
});

test('rejects language setup sites outside the current actor scope', function (): void {
    $baseLanguage = Language::factory()->createOne();
    $assignedSite = Site::factory()
        ->state(['language_id' => $baseLanguage->id])
        ->has(SiteDomain::factory()->state(['language_id' => $baseLanguage->id]))
        ->create();
    $otherSite = Site::factory()
        ->state(['language_id' => $baseLanguage->id])
        ->has(SiteDomain::factory()->state(['language_id' => $baseLanguage->id]))
        ->create();

    test()->actingAs(createScopedUserForManageLanguagesTest(collect([$assignedSite->getKey()])));

    $languageData = Language::factory()->make([
        'code' => 'scope' . bin2hex(random_bytes(3)),
    ]);

    Livewire::test(ManageLanguages::class)
        ->assertSuccessful()
        ->callAction(
            CreateAction::class,
            data: [
                'name' => $languageData->name,
                'code' => $languageData->code,
                'locale' => $languageData->locale,
                'flag' => $languageData->flag,
                'order' => $languageData->order,
                'setup' => true,
                'setup_sites' => [$assignedSite->getKey(), $otherSite->getKey()],
            ],
        )
        ->assertHasFormErrors([
            'setup_sites.1',
        ]);

    assertDatabaseMissing('languages', [
        'code' => $languageData->code,
    ]);
});

test('scopes language site counts for non-global users', function (): void {
    $language = Language::factory()->createOne();
    $assignedSite = Site::factory()
        ->state(['language_id' => $language->id])
        ->has(SiteDomain::factory()->state(['language_id' => $language->id]))
        ->create();
    Site::factory()
        ->state(['language_id' => $language->id])
        ->has(SiteDomain::factory()->state(['language_id' => $language->id]))
        ->create();

    test()->actingAs(createScopedUserForManageLanguagesTest(collect([$assignedSite->getKey()])));

    $languageWithCount = LanguageResource::getEloquentQuery()->firstWhere('id', $language->getKey());

    expect($languageWithCount?->getAttribute('sites_count'))->toBe(1);
});

test('can not create language', function (): void {
    Livewire::test(ManageLanguages::class)
        ->assertSuccessful()
        ->callAction(
            CreateAction::class,
            data: [
                'name' => '',
                'code' => '',
                'locale' => '',
                'flag' => '',
                'order' => '',
            ],
        )
        ->assertHasFormErrors([
            'name' => ['required'],
            'code' => ['required'],
            'locale' => ['required'],
            'flag' => ['required'],
            'order' => ['required'],
        ])
        ->assertCountTableRecords(0);
});

test('can update language', function (): void {
    $language = Language::factory()->createOne();

    $newLanguage = Language::factory()->make();

    Livewire::test(ManageLanguages::class)
        ->assertSuccessful()
        ->callAction(
            TestAction::make(EditAction::class)->table($language),
            data: [
                'name' => $newLanguage->name,
                'code' => $newLanguage->code,
                'locale' => $newLanguage->locale,
                'flag' => $newLanguage->flag,
                'meta' => [
                    'rtl' => true,
                ],
                'order' => $newLanguage->order,
                'default' => true,
                'status' => '0',
            ],
        )
        ->assertHasNoFormErrors();

    expect($language->refresh())
        ->name->toBe($newLanguage->name)
        ->code->toBe($newLanguage->code)
        ->locale->toBe($newLanguage->locale)
        ->flag->toBe($newLanguage->flag)
        ->meta->toMatchArray(['rtl' => true])
        ->order->toBe($newLanguage->order)
        ->default->toBeTrue()
        ->status->toBeFalse();
});

test('can not update language', function (): void {
    $language = Language::factory()->createOne();

    Livewire::test(ManageLanguages::class)
        ->assertSuccessful()
        ->callAction(
            TestAction::make(EditAction::class)->table($language),
            data: [
                'name' => '',
                'code' => '',
                'locale' => '',
                'flag' => '',
                'order' => '',
            ],
        )
        ->assertHasFormErrors([
            'name' => ['required'],
            'code' => ['required'],
            'locale' => ['required'],
            'flag' => ['required'],
            'order' => ['required'],
        ]);
});

test('can delete language', function (): void {
    $language = Language::factory()->createOne();

    Livewire::test(ManageLanguages::class)
        ->assertSuccessful()
        ->assertCountTableRecords(1)
        ->callAction(TestAction::make(DeleteAction::class)->table($language))
        ->assertCountTableRecords(0);

    assertSoftDeleted($language, ['id' => $language->id]);
});

test('can not delete language if it is used', function (): void {
    $language = Language::factory()->createOne();
    Site::factory()->language($language)->create();

    Livewire::test(ManageLanguages::class)
        ->assertSuccessful()
        ->assertCountTableRecords(1)
        ->callAction(TestAction::make(DeleteAction::class)->table($language))
        ->assertNotified(__(
            'capell-admin::message.language_not_deletable',
            ['name' => $language->name],
        ))
        ->assertCountTableRecords(1);

    assertDatabaseHas($language, ['id' => $language->id]);
});

test('can group delete languages', function (): void {
    $languages = Language::factory()
        ->sequence(fn (Sequence $sequence): array => ['code' => 'test-' . $sequence->index])
        ->count(5)->create();

    Livewire::test(ManageLanguages::class)
        ->assertSuccessful()
        ->selectTableRecords($languages)
        ->callAction(TestAction::make(DeleteBulkAction::class)->table()->bulk());

    foreach ($languages as $language) {
        assertSoftDeleted($language, ['id' => $language->id]);
    }
});
