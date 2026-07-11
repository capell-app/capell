<?php

declare(strict_types=1);

use Capell\Admin\Filament\Imports\RedirectImporter;
use Capell\Core\Models\Language;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Filament\Actions\Imports\Models\Import;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Validation\ValidationException;

/** @param SupportCollection<int, int> $assignedSiteIds */
function createScopedUserForRedirectImporterTest(SupportCollection $assignedSiteIds): Authenticatable
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

        /** @return SupportCollection<int, int> */
        public function getAssignedSiteIds(): SupportCollection
        {
            return $this->assignedSiteIds;
        }
    };

    $user->forceFill([
        'name' => 'Redirect Importer User',
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('password'),
    ]);
    $user->assignedSiteIds = $assignedSiteIds;

    return $user;
}

function runRedirectImporterForRedirectImporterTest(int $siteId, int $languageId, string $targetUrl = '/new-url'): void
{
    $importer = new RedirectImporter(
        new Import,
        [
            'url' => 'url',
            'target_url' => 'target_url',
        ],
        [
            'site_id' => $siteId,
            'language_id' => $languageId,
        ],
    );

    $importer([
        'url' => '/old-url',
        'target_url' => $targetUrl,
    ]);
}

it('rejects redirect imports for sites the actor is not assigned to', function (): void {
    $site = Site::factory()->withTranslations()->create();
    $language = $site->language;

    test()->actingAs(createScopedUserForRedirectImporterTest(collect()));

    expect(function () use ($site, $language): void {
        runRedirectImporterForRedirectImporterTest($site->getKey(), $language->getKey());
    })
        ->toThrow(ValidationException::class);

    expect(PageUrl::query()->where('url', '/old-url')->exists())->toBeFalse();
});

it('rejects redirect imports when the language is not attached to the selected site', function (): void {
    $siteLanguage = Language::factory()->createOne();
    $otherLanguage = Language::factory()->createOne();
    $site = Site::factory()->language($siteLanguage)->withTranslations($siteLanguage)->create();

    test()->actingAs(createScopedUserForRedirectImporterTest(collect([$site->getKey()])));

    expect(function () use ($site, $otherLanguage): void {
        runRedirectImporterForRedirectImporterTest($site->getKey(), $otherLanguage->getKey());
    })
        ->toThrow(ValidationException::class);

    expect(PageUrl::query()->where('url', '/old-url')->exists())->toBeFalse();
});

it('rejects redirect imports with unsafe targets', function (): void {
    $site = Site::factory()->withTranslations()->create();
    $language = $site->language;

    test()->actingAs(createScopedUserForRedirectImporterTest(collect([$site->getKey()])));

    expect(function () use ($site, $language): void {
        runRedirectImporterForRedirectImporterTest($site->getKey(), $language->getKey(), 'javascript:alert(1)');
    })
        ->toThrow(ValidationException::class);

    expect(PageUrl::query()->where('url', '/old-url')->exists())->toBeFalse();
});
