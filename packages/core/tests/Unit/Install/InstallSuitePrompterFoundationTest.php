<?php

declare(strict_types=1);

use Capell\Core\Actions\GetPluginsAction;
use Capell\Core\Data\PackageData;
use Capell\Core\Enums\PackageTypeEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Install\Cli\InstallSuitePrompter;
use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;

beforeEach(function (): void {
    CapellCore::clearPackages();

    foreach (['capell-app/admin', 'capell-app/frontend', 'capell-app/marketplace', 'capell-app/welcome-tour'] as $packageName) {
        CapellCore::registerPackage(name: $packageName);
    }

    CapellCore::getPackage('capell-app/admin')->requirements = ['capell-app/core'];
    CapellCore::getPackage('capell-app/frontend')->requirements = ['capell-app/core'];
    CapellCore::getPackage('capell-app/marketplace')->requirements = ['capell-app/admin', 'capell-app/core'];
    CapellCore::getPackage('capell-app/welcome-tour')->requirements = ['capell-app/admin', 'capell-app/core'];
});

it('keeps the default packages the plain checklist pre-ticks when a suite installs what they build on', function (): void {
    expect(resolve(InstallSuitePrompter::class)->foundationPackages(['capell-app/admin', 'capell-app/frontend']))
        ->toBe(['capell-app/admin', 'capell-app/frontend', 'capell-app/marketplace', 'capell-app/welcome-tour']);
});

it('adds no admin-side defaults to a suite that leaves the admin out', function (): void {
    $prompter = resolve(InstallSuitePrompter::class);

    expect($prompter->foundationPackages([]))->toBe([])
        ->and($prompter->foundationPackages(['capell-app/frontend']))->toBe(['capell-app/frontend']);
});

it('asks only what you are building in the compact form, defaults to the marketing site and installs its free extensions', function (): void {
    config(['capell.install.recommendations' => [
        'blog' => [
            'label' => 'Blog', 'description' => 'Articles.', 'packages' => ['capell-app/admin', 'capell-app/frontend'],
            'theme' => 'default', 'demo' => true, 'order' => 10,
        ],
        'marketing' => [
            'label' => 'Marketing site', 'description' => 'A marketing site.', 'packages' => ['capell-app/admin', 'capell-app/frontend'],
            'recommended' => ['vendor/free-forms' => 'Forms.', 'vendor/licensed' => 'A paid feature.'],
            'optional' => ['vendor/extra' => 'Not wanted by default.'],
            'theme' => 'default', 'demo' => true, 'order' => 20,
        ],
    ]]);
    GetPluginsAction::mock()->shouldReceive('handle')->andReturn(collect([
        'vendor/free-forms' => new PackageData(name: 'vendor/free-forms', type: PackageTypeEnum::Plugin, isPaid: false),
        'vendor/licensed' => new PackageData(name: 'vendor/licensed', type: PackageTypeEnum::Plugin, isPaid: true),
        'vendor/extra' => new PackageData(name: 'vendor/extra', type: PackageTypeEnum::Plugin, isPaid: false),
    ]));
    $fallback = new ReflectionProperty(Prompt::class, 'shouldFallback');
    $previousFallback = $fallback->getValue();
    // One Enter: accept the default suite. Any further prompt would run out of keys and fail.
    Prompt::fake([Key::ENTER]);
    $fallback->setValue(null, false);
    try {
        $selection = resolve(InstallSuitePrompter::class)->prompt(compact: true);

        expect($selection?->packages)->toContain('capell-app/admin', 'capell-app/frontend', 'vendor/free-forms')
            ->and($selection?->packages)->not->toContain('vendor/licensed')
            ->and($selection?->packages)->not->toContain('vendor/extra')
            ->and($selection?->demo)->toBeTrue();
        Prompt::assertStrippedOutputContains('Marketing site');
        Prompt::assertStrippedOutputDoesntContain("I'll choose my own packages");
        Prompt::assertStrippedOutputDoesntContain('Search for more extensions?');
    } finally {
        $fallback->setValue(null, $previousFallback);
        GetPluginsAction::clearFake();
    }
});
