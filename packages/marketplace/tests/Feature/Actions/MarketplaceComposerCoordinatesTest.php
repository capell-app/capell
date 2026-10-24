<?php

declare(strict_types=1);

use Capell\Marketplace\Actions\CreateExtensionAcquisitionAction;
use Capell\Marketplace\Actions\CreateExtensionUpdateAcquisitionAction;
use Capell\Marketplace\Data\ExtensionListingData;
use Capell\Marketplace\Data\MarketplaceComposerResultData;
use Capell\Marketplace\Support\ProcessMarketplaceComposerRunner;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config([
        'capell-marketplace.instance.id' => '00000000-0000-4000-8000-000000000123',
        'capell-marketplace.marketplace.webhook_secret' => 'test-signing-secret',
    ]);
});

function coordinateTestListing(string $name = 'publisher/extension', ?string $version = '2.4.0', bool $paid = false): ExtensionListingData
{
    return new ExtensionListingData('extension', 'Extension', $name, 'package', null, $paid ? 100 : 0, $paid, null, null, $version);
}

it('refuses malformed listing coordinates before authorizing an installation', function (string $name, string $version): void {
    Http::fake();

    expect(fn (): mixed => CreateExtensionAcquisitionAction::run(coordinateTestListing($name, $version)))
        ->toThrow(UnexpectedValueException::class);

    Http::assertNothingSent();
})->with([
    ['--repository', '2.4.0'],
    ['publisher/extension:dev-main', '2.4.0'],
    ['Publisher/extension', '2.4.0'],
    ['publisher/extension', 'not a version'],
    ['publisher/extension', "2.4.0\n--update-no-dev"],
]);

it('refuses malformed authorization constraints', function (string $constraint): void {
    config(['capell-marketplace.marketplace.base_url' => 'https://marketplace.test/api']);
    Http::fake(['*' => Http::response(['data' => [
        'composer_name' => 'publisher/extension', 'version_constraint' => $constraint,
    ]])]);

    expect(fn (): mixed => CreateExtensionAcquisitionAction::run(coordinateTestListing(paid: true), hostedFlowAuthorized: true))
        ->toThrow(UnexpectedValueException::class);
})->with(['not a constraint', "^2.4\n--no-plugins", 'dev-main as 2.4.0', 'https://packages.test/archive.zip']);

it('refuses upgrade authorizations that allow an installed version or downgrade', function (string $constraint): void {
    config(['capell-marketplace.marketplace.base_url' => 'https://marketplace.test/api']);
    Http::fake(['*' => Http::response(['data' => [
        'composer_name' => 'publisher/extension', 'version_constraint' => $constraint,
    ]])]);

    expect(fn (): mixed => CreateExtensionUpdateAcquisitionAction::run(coordinateTestListing(paid: true), '2.1.0'))
        ->toThrow(UnexpectedValueException::class);
})->with(['1.9.0', '^2.0', '*', '^2.4 || 1.9.0']);

it('accepts valid publisher coordinates and Composer version ranges', function (string $version): void {
    Http::fake();
    $acquisition = CreateExtensionAcquisitionAction::run(coordinateTestListing(version: $version));

    expect($acquisition->composerName)->toBe('publisher/extension')
        ->and($acquisition->versionConstraint)->toBe('^' . $version);
})->with(['2.4.0', '2.4.0-beta.1', '0.3.1']);

it('rejects invalid persisted coordinates before starting either composer execution path', function (bool $authenticated): void {
    // If the coordinate guard regresses, the release-root guard still prevents
    // a real Composer process from starting in this test.
    config(['capell.release_root_mode' => 'immutable']);
    $runner = new ProcessMarketplaceComposerRunner;
    expect(fn (): MarketplaceComposerResultData => $authenticated
        ? $runner->requireWithComposerAuth('invalid/package:dev-main', '^2.4', 5, [])
        : $runner->require('publisher/extension', 'not a constraint', 5))
        ->toThrow(UnexpectedValueException::class);
})->with([false, true]);

it('uses a valid protected update authorization when optional release metadata is absent', function (): void {
    config(['capell-marketplace.marketplace.base_url' => 'https://marketplace.test/api']);
    Http::fake(['*' => Http::response(['data' => [
        'composer_name' => 'publisher/extension', 'version_constraint' => '^2.4',
    ]])]);

    $acquisition = CreateExtensionUpdateAcquisitionAction::run(coordinateTestListing(version: null, paid: true), '2.1.0');

    expect($acquisition->composerName)->toBe('publisher/extension')
        ->and($acquisition->versionConstraint)->toBe('^2.4');
});
