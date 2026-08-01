<?php

declare(strict_types=1);

use Capell\Admin\Actions\Layouts\BuildLayoutDeletionImpactAction;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Collection;

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

it('counts every registered variation within the current actor site scope when no aggregate is loaded', function (): void {
    $layout = Layout::factory()->createOne();
    $assignedSite = Site::factory()->createOne();
    $hiddenSite = Site::factory()->createOne();

    Page::factory()->count(2)->site($assignedSite)->layout($layout)->create();
    Page::factory()->count(3)->site($hiddenSite)->layout($layout)->create();

    $user = new class extends Authenticatable implements FilamentUser
    {
        /** @use HasFactory<Factory<static>> */
        use HasFactory;

        /** @var Collection<int, int> */
        public Collection $assignedSiteIds;

        protected $table = 'users';

        public function canAccessPanel(Panel $panel): bool
        {
            return true;
        }

        /** @return Collection<int, int> */
        public function getAssignedSiteIds(): Collection
        {
            return $this->assignedSiteIds;
        }

        public function isGlobalAdmin(): bool
        {
            return false;
        }
    };

    $user->forceFill([
        'name' => 'Scoped layout impact user',
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('password'),
    ]);
    $user->assignedSiteIds = collect([$assignedSite->getKey()]);

    test()->actingAs($user);

    $impact = BuildLayoutDeletionImpactAction::run($layout);

    expect($impact->knownReferenceCount)->toBe(2)
        ->and($impact->authoritative)->toBeTrue()
        ->and($impact->affectedLabel)->toBe('2 known pages');
});
