<?php

declare(strict_types=1);

use Capell\Core\Data\Extensions\ExtensionVerificationIssueData;
use Capell\Core\Support\Extensions\ExtensionIntegrityVerifier;
use Capell\Core\Support\Manifest\ManifestValidator;
use Illuminate\Filesystem\Filesystem;

beforeEach(function (): void {
    $this->tree = sys_get_temp_dir() . '/capell-verifier-' . bin2hex(random_bytes(8));
    $this->vendor = $this->tree . '/vendor';
    $this->packageDir = $this->vendor . '/vendor/example';
    mkdir($this->vendor . '/composer', 0777, true);
    mkdir($this->packageDir . '/src', 0777, true);
    $this->package = [
        'name' => 'vendor/example', 'version' => '2.0.0', 'type' => 'library',
        'install-path' => '../vendor/example',
        'autoload' => ['psr-4' => ['Vendor\\Example\\' => 'src/']],
        'dist' => ['shasum' => 'abc123', 'reference' => 'ref123', 'url' => 'https://example.test/archive.zip'],
    ];
    $this->manifest = json_decode((string) file_get_contents(__DIR__ . '/../../fixtures/manifest-v3/valid-premium-package.json'), true, flags: JSON_THROW_ON_ERROR);
    $this->manifest['name'] = 'vendor/example';
    $this->manifest['providers']['runtime'] = ['Vendor\\Example\\Provider'];
    $this->manifest['contributes'] = [];
    $this->manifest['healthChecks'] = [];
    file_put_contents($this->packageDir . '/src/Provider.php', '<?php');
    $this->writeTree = function (bool $flat = false): void {
        file_put_contents($this->vendor . '/composer/installed.json', json_encode($flat ? [$this->package] : ['packages' => [$this->package]], JSON_THROW_ON_ERROR));
        file_put_contents($this->packageDir . '/composer.json', json_encode(['name' => $this->package['name'], 'autoload' => $this->package['autoload']], JSON_THROW_ON_ERROR));
        file_put_contents($this->packageDir . '/capell.json', json_encode($this->manifest, JSON_THROW_ON_ERROR));
    };
    $this->verifier = new ExtensionIntegrityVerifier($this->vendor, new ManifestValidator);
});

afterEach(function (): void {
    new Filesystem()->deleteDirectory($this->tree);
});

it('passes a valid package and records its installed distribution', function (bool $flat): void {
    ($this->writeTree)($flat);
    $report = $this->verifier->verify('vendor/example', '^2.0', '1.0.0', 'example', 'abc123');
    expect($report->passed)->toBeTrue()->and($report->failures)->toEqual([])->and($report->warnings)->toEqual([])
        ->and([$report->installedVersion, $report->distSha, $report->distReference, $report->distHost, $report->installPath])
        ->toEqual(['2.0.0', 'abc123', 'ref123', 'example.test', realpath($this->packageDir)]);
})->with([false, true]);

it('reports an absent package', function (): void {
    ($this->writeTree)();
    $report = $this->verifier->verify('vendor/missing');
    expect($report->passed)->toBeFalse()->and($report->failures[0]->code)->toBe('not_installed');
});

it('reports an installed version outside the constraint', function (): void {
    ($this->writeTree)();
    expect(array_column($this->verifier->verify('vendor/example', '^3.0')->failures, 'code'))->toContain('version_outside_constraint');
});

it('reports an installed version that is not an upgrade', function (): void {
    ($this->writeTree)();
    expect(array_column($this->verifier->verify('vendor/example', previousVersion: '2.0.0')->failures, 'code'))->toContain('not_an_upgrade');
});

it('reports a Composer plugin declaration', function (array $declaration): void {
    $this->package = array_replace($this->package, $declaration);
    ($this->writeTree)();
    expect(array_column($this->verifier->verify('vendor/example')->failures, 'code'))->toContain('composer_plugin');
})->with([[['type' => 'composer-plugin']], [['require' => ['composer-plugin-api' => '^2']]], [['require-dev' => ['composer-plugin-api' => '^2']]]]);

it('reports a missing manifest', function (): void {
    ($this->writeTree)();
    unlink($this->packageDir . '/capell.json');
    expect(array_column($this->verifier->verify('vendor/example')->failures, 'code'))->toContain('manifest_missing');
});

it('reports the structural validator message for an invalid manifest', function (): void {
    $this->manifest['manifest-version'] = 2;
    ($this->writeTree)();
    $issue = collect($this->verifier->verify('vendor/example')->failures)->firstWhere('code', 'manifest_invalid');
    expect($issue)->not->toBeNull()->and($issue?->message)->toContain('manifest-version');
});

it('reports either manifest identity mismatch', function (string $field): void {
    $this->manifest[$field] = $field === 'name' ? 'vendor/other' : 'other';
    ($this->writeTree)();
    expect(array_column($this->verifier->verify('vendor/example', expectedSlug: 'example')->failures, 'code'))->toContain('manifest_identity_mismatch');
})->with(['name', 'slug']);

it('rejects unsafe or missing autoload paths', function (string $kind, string $path): void {
    $this->package['autoload'][$kind] = in_array($kind, ['psr-4', 'psr-0'], true) ? ['Other\\' => $path] : [$path];
    ($this->writeTree)();
    expect(array_column($this->verifier->verify('vendor/example')->failures, 'code'))->toContain('autoload_escapes_package');
})->with([['psr-4', '../outside'], ['psr-0', '/tmp'], ['classmap', 'missing'], ['files', 'missing.php']]);

it('reports a missing declared class file without loading it', function (string $section): void {
    if ($section === 'provider') {
        unlink($this->packageDir . '/src/Provider.php');
    } elseif ($section === 'contribution') {
        $this->manifest['contributes'] = [['type' => 'route', 'class' => 'Vendor\\Example\\Missing']];
    } else {
        $this->manifest['healthChecks'] = [['key' => 'missing', 'label' => 'Missing', 'class' => 'Vendor\\Example\\Missing']];
    }

    ($this->writeTree)();
    expect(array_column($this->verifier->verify('vendor/example')->failures, 'code'))->toContain('provider_file_missing')
        ->and(class_exists('Vendor\\Example\\Missing', false))->toBeFalse();
})->with(['provider', 'contribution', 'health']);

it('reports a missing expected checksum', function (): void {
    unset($this->package['dist']['shasum']);
    ($this->writeTree)();
    expect(array_column($this->verifier->verify('vendor/example', expectedDistSha: 'abc')->failures, 'code'))->toContain('checksum_missing');
});

it('reports a mismatched checksum', function (): void {
    ($this->writeTree)();
    expect(array_column($this->verifier->verify('vendor/example', expectedDistSha: 'different')->failures, 'code'))->toContain('checksum_mismatch');
});

it('does not require a checksum when none is expected', function (): void {
    unset($this->package['dist']['shasum']);
    ($this->writeTree)();
    expect($this->verifier->verify('vendor/example')->passed)->toBeTrue();
});

it('exempts trusted core from plugin and identity checks but retains other checks', function (): void {
    $this->package['name'] = 'capell-app/core';
    $this->package['type'] = 'composer-plugin';
    ($this->writeTree)();
    $report = $this->verifier->verify('capell-app/core', expectedSlug: 'other', expectedDistSha: 'wrong');
    expect(array_column($report->failures, 'code'))->toContain('checksum_mismatch')->toContainNone(['composer_plugin', 'manifest_identity_mismatch']);
});

it('permits Composer plugins when explicitly configured', function (): void {
    config()->set('capell.extensions.verification.allow_composer_plugins', true);
    $this->package['type'] = 'composer-plugin';
    ($this->writeTree)();
    expect($this->verifier->verify('vendor/example')->passed)->toBeTrue();
});

it('bounds recorded failures while counting every omitted issue', function (): void {
    $this->package['autoload']['files'] = array_fill(0, 1000, '../outside.php');
    ($this->writeTree)();
    $report = $this->verifier->verify('vendor/example');

    expect($report->passed)->toBeFalse()
        ->and($report->failures)->toHaveCount(50)
        ->and($report->omittedIssueCount)->toBe(950)
        ->and($report->failures[0]->code)->toBe('autoload_escapes_package');
});

it('warns instead of comparing a checksum for a different release', function (): void {
    ($this->writeTree)();
    $report = $this->verifier->verify('vendor/example', expectedDistSha: 'different', releaseChecksumVersion: '1.0.0');

    expect($report->passed)->toBeTrue()
        ->and($report->failures)->toBeEmpty()
        ->and(array_column($report->warnings, 'code'))->toBe(['checksum_not_for_installed_release']);
});

it('rejects a source install when its release checksum is expected', function (): void {
    unset($this->package['dist']);
    ($this->writeTree)();
    $report = $this->verifier->verify('vendor/example', expectedDistSha: str_repeat('a', 40), releaseChecksumVersion: '2.0.0');

    expect($report->passed)->toBeFalse()
        ->and(array_column($report->failures, 'code'))->toContain('checksum_missing')
        ->and($report->warnings)->toBeEmpty();
});

it('checks dependency plugins and autoload paths without requiring a Capell manifest', function (): void {
    $this->package['type'] = 'composer-plugin';
    ($this->writeTree)();
    unlink($this->packageDir . '/capell.json');
    $report = $this->verifier->verifyDependency('vendor/example');

    expect($report->passed)->toBeFalse()
        ->and(array_column($report->failures, 'code'))->toContain('composer_plugin')->not->toContain('manifest_missing');
});

it('uses safe manifest text when diagnostics are not supplied', function (): void {
    $description = ExtensionVerificationIssueData::describe('manifest_invalid', 'vendor/example');
    $unknown = ExtensionVerificationIssueData::describe('unknown', 'vendor/example');

    expect($description['message'])->toContain('vendor/example')->not->toContain(':reason')
        ->and($description['next_action'])->not->toBeEmpty()
        ->and($unknown)->toBe(['message' => '', 'next_action' => '']);
});
