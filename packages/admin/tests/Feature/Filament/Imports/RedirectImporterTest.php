<?php

declare(strict_types=1);

use Capell\Admin\Filament\Imports\RedirectImporter;
use Capell\Core\Models\Language;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Support\TableName;
use Filament\Actions\ActionsServiceProvider;
use Filament\Actions\Imports\Models\Import;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

/** An import started in the Capell admin panel by the signed-in user, as the import action records it. */
function startedRedirectImportForRedirectImporterTest(): Import
{
    if (! Schema::hasTable('imports')) {
        (require dirname((string) new ReflectionClass(ActionsServiceProvider::class)->getFileName(), 2) . '/database/migrations/create_imports_table.php')->up();
    }

    $user = auth()->user();
    throw_unless($user instanceof Authenticatable, LogicException::class, 'Expected a signed-in importer.');
    $user->save();

    $import = new Import;
    $import->forceFill(['file_name' => 'redirects.csv', 'file_path' => 'redirects.csv', 'importer' => RedirectImporter::class, 'total_rows' => 1, 'user_id' => $user->getKey()])->save();
    DB::table(TableName::resolve('admin_transfer_origins'))->insert(['transfer_type' => 'import', 'transfer_key' => (string) $import->getKey(), 'panel' => 'admin', 'host' => 'localhost']);

    return $import;
}

function runRedirectImporterForRedirectImporterTest(int $siteId, int $languageId, string $targetUrl = '/new-url'): void
{
    $importer = new RedirectImporter(
        startedRedirectImportForRedirectImporterTest(),
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
        ->toThrow(function (Throwable $exception): void {
            throw_unless($exception instanceof ValidationException, $exception);
            expect($exception->errors())->toHaveKey('site_id')->not->toHaveKey('import');
        });

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
        ->toThrow(function (Throwable $exception): void {
            throw_unless($exception instanceof ValidationException, $exception);
            expect($exception->errors())->toHaveKey('language_id')->not->toHaveKey('import');
        });

    expect(PageUrl::query()->where('url', '/old-url')->exists())->toBeFalse();
});

it('rejects redirect imports with unsafe targets', function (): void {
    $site = Site::factory()->withTranslations()->create();
    $language = $site->language;

    test()->actingAs(createScopedUserForRedirectImporterTest(collect([$site->getKey()])));

    expect(function () use ($site, $language): void {
        runRedirectImporterForRedirectImporterTest($site->getKey(), $language->getKey(), 'javascript:alert(1)');
    })
        ->toThrow(function (Throwable $exception): void {
            throw_unless($exception instanceof ValidationException, $exception);
            expect($exception->errors())->toHaveKey('target_url')->not->toHaveKey('import');
        });

    expect(PageUrl::query()->where('url', '/old-url')->exists())->toBeFalse();
});
