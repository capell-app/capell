<?php

declare(strict_types=1);

use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Install\Cli\InstallSuitePrompter;

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
