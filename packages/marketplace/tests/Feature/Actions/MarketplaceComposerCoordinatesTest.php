<?php

declare(strict_types=1);

use Capell\Marketplace\Actions\CreateExtensionAcquisitionAction;
use Capell\Marketplace\Actions\CreateExtensionUpdateAcquisitionAction;
use Capell\Marketplace\Data\ExtensionListingData;
use Capell\Marketplace\Data\MarketplaceComposerResultData;
use Capell\Marketplace\Enums\MarketplaceOperationType;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Capell\Marketplace\Models\MarketplaceInstallIntent;
use Capell\Marketplace\Support\MarketplaceComposerCommand;
use Capell\Marketplace\Support\ProcessMarketplaceComposerRunner;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;

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

it('preserves authorized constraints as one literal shell argument', function (string $constraint): void {
    config(['capell-marketplace.marketplace.base_url' => 'https://marketplace.test/api']);
    Http::fake(['*' => Http::response(['data' => [
        'composer_name' => 'publisher/extension', 'version_constraint' => $constraint,
    ]])]);
    $acquisition = CreateExtensionAcquisitionAction::run(coordinateTestListing(paid: true), hostedFlowAuthorized: true);
    $argument = substr((string) $acquisition->composerCommand, strlen('composer require '));
    $process = Process::fromShellCommandline("printf '%s\\0' " . $argument);
    $process->mustRun();

    expect($acquisition->versionConstraint)->toBe($constraint)
        ->and($process->getOutput())->toBe('publisher/extension:' . $constraint . "\0");
})->with(['^2.4 || ^3.0', '>2.3 <3.0', '*', 'dev-main;true', 'dev-main$(printf)', "dev-main'quote"]);

it('quotes upgrade commands containing Composer range operators', function (): void {
    config(['capell-marketplace.marketplace.base_url' => 'https://marketplace.test/api']);
    Http::fake(['*' => Http::response(['data' => [
        'composer_name' => 'publisher/extension', 'version_constraint' => '>2.3 <3.0 || ^4.0',
    ]])]);
    $acquisition = CreateExtensionUpdateAcquisitionAction::run(coordinateTestListing(paid: true), '2.1.0');

    expect($acquisition->composerCommand)->toBe("composer require 'publisher/extension:>2.3 <3.0 || ^4.0'");
});

it('renders legacy stored commands safely for operation and pending theme readers', function (): void {
    $legacyCommand = 'composer require publisher/extension:dev-main;true';
    $coordinates = [
        'composer_name' => 'publisher/extension',
        'version_constraint' => 'dev-main;true',
        'composer_command' => $legacyCommand,
    ];
    $intent = new MarketplaceInstallIntent($coordinates);
    $attempt = new MarketplaceInstallAttempt([...$coordinates, 'operation' => MarketplaceOperationType::Update]);

    expect($intent->composer_command)->toBe("composer require 'publisher/extension:dev-main;true'")
        ->and($attempt->composer_command)->toBe("composer require 'publisher/extension:dev-main;true'")
        ->and($attempt->toArray()['composer_command'])->toBe("composer require 'publisher/extension:dev-main;true'")
        ->and($attempt->getAttributes()['composer_command'])->toBe($legacyCommand)
        ->and($intent->getAttributes()['composer_command'])->toBe($legacyCommand);

    $attempt->operation = MarketplaceOperationType::Uninstall;
    expect($attempt->composer_command)->toBe("composer remove 'publisher/extension'");
    $attempt->composer_command = null;
    expect($attempt->composer_command)->toBeNull();
    $attempt->composer_command = '';
    expect($attempt->composer_command)->toBe('');

    $intent->version_constraint = null;
    expect($intent->composer_command)->toBe("composer require 'publisher/extension:dev-main;true'")
        ->and(MarketplaceComposerCommand::remove('publisher/extension'))->toBe("composer remove 'publisher/extension'");
});

it('preserves constraints stored only in legacy command text', function (bool $quoted): void {
    $constraint = "dev-main'quote;true";
    $command = $quoted
        ? MarketplaceComposerCommand::require('publisher/extension', $constraint)
        : 'composer require publisher/extension:' . $constraint;
    $intent = new MarketplaceInstallIntent([
        'composer_name' => 'publisher/extension',
        'version_constraint' => null,
        'composer_command' => $command,
    ]);

    expect($intent->composer_command)->toBe("composer require 'publisher/extension:dev-main'\\''quote;true'")
        ->and($intent->getAttributes()['composer_command'])->toBe($command);
})->with(['legacy raw' => false, 'already quoted' => true]);

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
