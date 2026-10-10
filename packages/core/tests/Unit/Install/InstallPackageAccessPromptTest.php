<?php

declare(strict_types=1);

use Capell\Core\Actions\GetPluginsAction;
use Capell\Core\Contracts\Marketplace\ExtensionEntitlements;
use Capell\Core\Data\Marketplace\ExtensionLicenceDecisionData;
use Capell\Core\Data\PackageData;
use Capell\Core\Enums\PackageTypeEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Install\Cli\InstallSuitePrompter;
use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;

it('reviews paid download access before returning the suite selection', function (?array $decision, array $accessKeys, array $expected, string $message): void {
    CapellCore::clearPackages();
    config(['capell.install.recommendations' => ['suite' => [
        'label' => 'Suite', 'description' => 'A test suite.',
        'recommended' => ['vendor/licensed' => 'An optional paid feature.'],
    ]]]);
    GetPluginsAction::mock()->shouldReceive('handle')->andReturn(collect([
        'vendor/licensed' => new PackageData(name: 'vendor/licensed', type: PackageTypeEnum::Plugin, slug: 'licensed', isPaid: true),
    ]));
    $entitlements = Mockery::mock(ExtensionEntitlements::class);
    $check = $entitlements->shouldReceive('licenceDecision')->with('licensed', 'install', 'example.test')->once();
    if ($decision === null) {
        $check->andThrow(new RuntimeException('Account access unavailable'));
    } else {
        $check->andReturn(ExtensionLicenceDecisionData::fromApiResponse($decision));
    }

    app()->instance(ExtensionEntitlements::class, $entitlements);
    $fallback = new ReflectionProperty(Prompt::class, 'shouldFallback');
    $previousFallback = $fallback->getValue();
    Prompt::fake([Key::ENTER, Key::SPACE, Key::ENTER, Key::ENTER, ...$accessKeys]);
    $fallback->setValue(null, false);
    try {
        $selection = resolve(InstallSuitePrompter::class)->prompt(siteUrl: 'https://example.test:8443/blog');
        expect($selection?->packages)->toBe($expected);
        Prompt::assertStrippedOutputContains($message);
    } finally {
        $fallback->setValue(null, $previousFallback);
        GetPluginsAction::clearFake();
    }
})->with([
    'verified licence' => [['licence_status' => 'active', 'can_download' => true, 'can_install' => true], [], ['vendor/licensed'], 'Included in your licence'],
    'denied licence defaults to deselection' => [['licence_status' => 'expired'], [Key::ENTER], [], 'does not authorise this installation'],
    'separate Composer credentials can be checked' => [null, [Key::DOWN, Key::DOWN, Key::ENTER], ['vendor/licensed'], 'check Composer download access'],
]);
