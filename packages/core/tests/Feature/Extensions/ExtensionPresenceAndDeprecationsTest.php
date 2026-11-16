<?php

declare(strict_types=1);

use Capell\Core\Actions\Extensions\AuditExtensionContractsAction;
use Capell\Core\Enums\Diagnostics\DoctorCheckSeverity;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Http\Middleware\DeprecatedHttpSurface;
use Capell\Core\Support\Diagnostics\Checks\DeprecationsCheck;
use Capell\Core\Support\Diagnostics\Checks\ManifestContractsCheck;
use Capell\Core\Support\Extensions\Deprecations;
use Capell\Core\Support\Extensions\ExtensionPresence;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

function presencePackageDirectory(string $composerName): string
{
    $directory = sys_get_temp_dir() . '/capell-presence-' . bin2hex(random_bytes(6));
    File::ensureDirectoryExists($directory);
    File::put($directory . '/composer.json', json_encode(['name' => $composerName], JSON_THROW_ON_ERROR));

    return $directory;
}

describe('ExtensionPresence', function (): void {
    it('treats a Composer package the registry does not know as inactive', function (): void {
        expect(ExtensionPresence::isActive('acme/never-registered'))->toBeFalse()
            ->and(ExtensionPresence::isActive('laravel/framework'))->toBeFalse();
    });

    it('is active only while the extension is installed and enabled', function (): void {
        CapellCore::registerPackage('acme/presence-on', path: presencePackageDirectory('acme/presence-on'));
        CapellCore::forcePackageInstalled('acme/presence-on');

        expect(ExtensionPresence::isActive('acme/presence-on'))->toBeTrue();

        CapellCore::forcePackageInstalled('acme/presence-on', false);

        expect(ExtensionPresence::isActive('acme/presence-on'))->toBeFalse();
    });

    it('is inactive when the registry lists the extension but its code is not on disk', function (): void {
        $directory = sys_get_temp_dir() . '/capell-presence-missing-' . bin2hex(random_bytes(6));
        CapellCore::registerPackage('acme/presence-missing', path: $directory);
        CapellCore::forcePackageInstalled('acme/presence-missing');

        expect(ExtensionPresence::isActive('acme/presence-missing'))->toBeFalse();
    });
});

describe('Deprecations', function (): void {
    it('keeps one entry per surface and counts repeat uses', function (): void {
        Deprecations::trigger('Acme\\OldThing::make()', 'Acme\\NewThing::make()', '5.0');
        Deprecations::trigger('Acme\\OldThing::make()', 'Acme\\NewThing::make()', '5.0');
        Deprecations::trigger('acme.legacy_option', 'acme.option', '2027-06-01');

        $recorded = resolve(Deprecations::class)->all();

        expect($recorded)->toHaveCount(2);
        expect($recorded[0]->toArray())->toBe(['surface' => 'Acme\\OldThing::make()', 'replacement' => 'Acme\\NewThing::make()', 'removal' => '5.0', 'occurrences' => 2]);
        expect($recorded[1]->surface)->toBe('acme.legacy_option');
    });

    it('refuses a deprecation without a replacement', function (): void {
        Deprecations::trigger('acme.surface', ' ', '5.0');
    })->throws(InvalidArgumentException::class, 'A deprecation needs a replacement.');

    it('passes the doctor check when nothing deprecated was used', function (): void {
        $result = resolve(DeprecationsCheck::class)->check();

        expect($result->passed)->toBeTrue();
        expect($result->id)->toBe('core.extensions.deprecations');
    });

    it('lists each used surface in the doctor as a warning that does not block', function (): void {
        Deprecations::trigger('acme.legacy_option', 'acme.option', '5.0');

        $result = resolve(DeprecationsCheck::class)->check();

        expect($result->passed)->toBeFalse();
        expect($result->severity)->toBe(DoctorCheckSeverity::Warning);
        expect($result->message)->toContain('acme.legacy_option', 'acme.option', '5.0');
        expect($result->evidence)->toBe(['deprecations' => [['surface' => 'acme.legacy_option', 'replacement' => 'acme.option', 'removal' => '5.0', 'occurrences' => 1]]]);
    });

    it('reports used surfaces in the extension audit and only once in the doctor', function (): void {
        Deprecations::trigger('acme.legacy_option', 'acme.option', '5.0');

        $deprecations = array_values(array_filter(
            AuditExtensionContractsAction::run(),
            static fn (array $result): bool => $result['manifest_path'] === AuditExtensionContractsAction::RUNTIME_DEPRECATIONS,
        ));

        expect($deprecations)->toHaveCount(1);
        expect($deprecations[0]['severity'])->toBe('warning');
        expect($deprecations[0]['context'])->toMatchArray(['surface' => 'acme.legacy_option', 'remediation' => 'Use acme.option instead.']);

        $manifest = resolve(ManifestContractsCheck::class)->check();
        $manifestWarnings = $manifest->evidence['warning_count'] ?? 0;

        expect($manifest->message)->not->toContain('acme.legacy_option');

        $withoutDeprecation = count(array_filter(
            AuditExtensionContractsAction::run(allowExportIgnoredScreenshots: true),
            static fn (array $result): bool => $result['severity'] === 'warning' && $result['manifest_path'] !== AuditExtensionContractsAction::RUNTIME_DEPRECATIONS,
        ));

        expect($manifestWarnings)->toBe($withoutDeprecation);
    });
});

describe('DeprecatedHttpSurface', function (): void {
    beforeEach(function (): void {
        Route::middleware(DeprecatedHttpSurface::ALIAS . ':legacy feed,2026-11-01,2027-05-01,/api/v2/feed')
            ->get('/__deprecated-feed', static fn (): string => 'feed');
        Route::middleware(DeprecatedHttpSurface::ALIAS . ':legacy export,2026-11-01,2027-05-01,the export action')
            ->get('/__deprecated-export', static fn (): string => 'export');
        Route::middleware(DeprecatedHttpSurface::ALIAS . ':broken,2026-11-01,May 2027,/api/v2')
            ->get('/__deprecated-broken', static fn (): string => 'broken');
    });

    it('sends Deprecation, Sunset and successor headers and records the use', function (): void {
        $response = $this->get('/__deprecated-feed');

        $response->assertOk()
            ->assertHeader('Deprecation', '@1793491200')
            ->assertHeader('Sunset', 'Sat, 01 May 2027 00:00:00 GMT')
            ->assertHeader('Link', '</api/v2/feed>; rel="successor-version"');

        expect(resolve(Deprecations::class)->all()[0]->toArray())
            ->toBe(['surface' => 'legacy feed', 'replacement' => '/api/v2/feed', 'removal' => '2027-05-01', 'occurrences' => 1]);
    });

    it('sends no successor link when the replacement is not an address', function (): void {
        $this->get('/__deprecated-export')
            ->assertOk()
            ->assertHeaderMissing('Link');
    });

    it('refuses a sunset that is not a calendar date', function (): void {
        $this->withoutExceptionHandling()->get('/__deprecated-broken');
    })->throws(InvalidArgumentException::class, 'The sunset date must be written as YYYY-MM-DD.');
});
