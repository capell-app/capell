<?php

declare(strict_types=1);

use Capell\Core\Data\Install\InstallHandoffFactsData;
use Capell\Core\Data\InstallInputData;
use Capell\Core\Data\Manifest\ExtensionInstallTipData;
use Capell\Core\Data\NewUserData;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Install\InstallHandoffTips;

beforeEach(function (): void {
    CapellCore::clearPackages();
});

/**
 * Register a package the way its manifest would describe it to the install handoff.
 *
 * @param  array<string, int>  $tips  tip variant => priority
 */
function registerHandoffPackage(string $name, array $tips = [], ?string $kind = null, ?string $themeKey = null): void
{
    CapellCore::registerPackage($name);
    $package = CapellCore::getPackage($name);
    $package->kind = $kind;
    $package->themeKey = $themeKey;
    $package->declaredInstallTips = array_map(
        static fn (string $variant, int $priority): ExtensionInstallTipData => new ExtensionInstallTipData($variant, $priority),
        array_keys($tips),
        array_values($tips),
    );
}

function installTipsInput(array $overrides = []): InstallInputData
{
    return new InstallInputData(...[
        'siteUrl' => 'https://example.test',
        'packages' => ['capell-app/core', 'capell-app/admin'],
        'languages' => ['en'],
        'demoContent' => false,
        'cachesToClear' => [],
        'generateSitemap' => false,
        'generateStaticSite' => false,
        ...$overrides,
    ]);
}

/** @return list<string> */
function installTipKeys(array $tips): array
{
    return array_column($tips, 'key');
}

it('tells a completed install how to sign in, edit the first page and change the theme', function (): void {
    registerHandoffPackage('capell-app/theme-foundation', kind: 'theme');

    $tips = (new InstallHandoffTips)->build(
        inputData: installTipsInput(['newUser' => new NewUserData('Ben', 'ben@example.test', 'hunter2')]),
        selectedPackages: ['capell-app/admin', 'capell-app/core', 'capell-app/theme-foundation'],
        completed: true,
        needsFrontendBuild: false,
        adminUrl: 'https://example.test/admin',
        adminEmail: 'ben@example.test',
        publicUrl: 'https://example.test/',
        firstPageStatus: 'editable',
    );

    expect(installTipKeys($tips))->toBe(['sign_in', 'first_page', 'theme'])
        ->and($tips[0]['detail'])->toContain('https://example.test/admin')->toContain('ben@example.test')
        ->and($tips[1]['detail'])->toContain('https://example.test/')->toContain('Pages')
        ->and($tips[2]['detail'])->toContain('capell-app/theme-foundation')->toContain('Themes')
        ->and(json_encode($tips, JSON_THROW_ON_ERROR))->not->toContain('hunter2');

    foreach ($tips as $tip) {
        expect($tip['label'])->not->toBe('')->and($tip['detail'])->not->toBe('');
    }
});

it('explains how to create a site when no page exists and names the existing administrator account', function (): void {
    $tips = (new InstallHandoffTips)->build(
        inputData: installTipsInput(['userId' => 7]),
        selectedPackages: ['capell-app/admin', 'capell-app/core'],
        completed: true,
        needsFrontendBuild: false,
        adminUrl: 'https://example.test/admin',
        adminEmail: null,
        publicUrl: 'https://example.test/',
        firstPageStatus: 'missing',
    );

    expect(installTipKeys($tips))->toBe(['sign_in', 'first_page'])
        ->and($tips[0]['detail'])->toContain('existing administrator')
        ->and($tips[1]['detail'])->toContain('Sites');
});

it('explains that the page is published and has no edit link when no Admin panel is installed', function (): void {
    $tips = (new InstallHandoffTips)->build(
        inputData: installTipsInput(),
        selectedPackages: ['capell-app/core'],
        completed: true,
        needsFrontendBuild: false,
        adminUrl: null,
        adminEmail: null,
        publicUrl: 'https://example.test/',
        firstPageStatus: 'present_unverified',
    );

    expect(installTipKeys($tips))->toBe(['sign_in', 'first_page'])
        ->and($tips[0]['detail'])->toContain('capell-app/admin')
        ->and($tips[1]['detail'])->toContain('https://example.test/')
        ->and($tips[1]['detail'])->not->toContain(':url');
});

it('sends the editor to Admin when the page is published but Admin has not loaded its edit link yet', function (): void {
    $tips = (new InstallHandoffTips)->build(
        inputData: installTipsInput(),
        selectedPackages: ['capell-app/admin'],
        completed: true,
        needsFrontendBuild: false,
        adminUrl: 'https://example.test/admin',
        adminEmail: null,
        publicUrl: 'https://example.test/',
        firstPageStatus: 'present_unverified',
    );

    expect(installTipKeys($tips))->toBe(['sign_in', 'first_page'])
        ->and($tips[1]['detail'])->toContain('https://example.test/')
        ->toContain('https://example.test/admin')
        ->not->toContain(':url')
        ->not->toContain(':admin_url');
});

it('skips the page tip when the first page state is unavailable', function (): void {
    $tips = (new InstallHandoffTips)->build(
        inputData: installTipsInput(),
        selectedPackages: ['capell-app/admin'],
        completed: true,
        needsFrontendBuild: false,
        adminUrl: 'https://example.test/admin',
        adminEmail: null,
        publicUrl: 'https://example.test/',
        firstPageStatus: 'unavailable',
    );

    expect(installTipKeys($tips))->toBe(['sign_in']);
});

it('points demo installs at the seeded content', function (): void {
    $tips = (new InstallHandoffTips)->build(
        inputData: installTipsInput(['demoContent' => true]),
        selectedPackages: ['capell-app/admin'],
        completed: true,
        needsFrontendBuild: false,
        adminUrl: 'https://example.test/admin',
        adminEmail: null,
        publicUrl: 'https://example.test/',
        firstPageStatus: 'editable',
    );

    expect($tips[1]['key'])->toBe('first_page')
        ->and($tips[1]['detail'])->toContain('Demo content');
});

it('derives the theme tip from the selected theme key when the theme was not a selected package', function (): void {
    registerHandoffPackage('capell-app/theme-saas', kind: 'theme', themeKey: 'saas');

    $tips = (new InstallHandoffTips)->build(
        inputData: installTipsInput(['selectedThemeKey' => 'saas']),
        selectedPackages: ['capell-app/admin'],
        completed: true,
        needsFrontendBuild: false,
        adminUrl: 'https://example.test/admin',
        adminEmail: null,
        publicUrl: 'https://example.test/',
        firstPageStatus: 'editable',
    );

    expect(installTipKeys($tips))->toBe(['sign_in', 'first_page', 'theme'])
        ->and($tips[2]['detail'])->toContain('capell-app/theme-saas');
});

it('adds at most two content package tips in priority order', function (): void {
    registerHandoffPackage('capell-app/form-builder', ['package_form_builder' => 20]);
    registerHandoffPackage('capell-app/events', ['package_events' => 30]);
    registerHandoffPackage('capell-app/bookings', ['package_bookings' => 50]);
    registerHandoffPackage('capell-app/html-cache');

    $tips = (new InstallHandoffTips)->build(
        inputData: installTipsInput(),
        selectedPackages: ['capell-app/admin', 'capell-app/bookings', 'capell-app/events', 'capell-app/form-builder', 'capell-app/html-cache'],
        completed: true,
        needsFrontendBuild: false,
        adminUrl: 'https://example.test/admin',
        adminEmail: null,
        publicUrl: 'https://example.test/',
        firstPageStatus: 'editable',
    );

    expect(installTipKeys($tips))->toBe(['sign_in', 'first_page', 'package:capell-app/form-builder', 'package:capell-app/events'])
        ->and($tips[2]['detail'])->toContain('Form Builder')
        ->and($tips[3]['detail'])->toContain('Events');
});

it('replaces the content tips with the build instruction until frontend assets are built', function (): void {
    $tips = (new InstallHandoffTips)->build(
        inputData: installTipsInput(),
        selectedPackages: ['capell-app/admin', 'capell-app/blog', 'capell-app/theme-foundation'],
        completed: true,
        needsFrontendBuild: true,
        adminUrl: 'https://example.test/admin',
        adminEmail: null,
        publicUrl: 'https://example.test/',
        firstPageStatus: 'editable',
    );

    expect(installTipKeys($tips))->toBe(['frontend_build', 'sign_in'])
        ->and($tips[0]['detail'])->toContain('php artisan capell:frontend-after-install --apply --no-interaction');
});

it('only asks for a review when the install is incomplete', function (): void {
    $tips = (new InstallHandoffTips)->build(
        inputData: installTipsInput(),
        selectedPackages: ['capell-app/admin', 'capell-app/blog'],
        completed: false,
        needsFrontendBuild: false,
        adminUrl: 'https://example.test/admin',
        adminEmail: 'ben@example.test',
        publicUrl: 'https://example.test/',
        firstPageStatus: 'editable',
    );

    expect(installTipKeys($tips))->toBe(['review'])
        ->and($tips[0]['detail'])->toContain('capell:install');
});

it('builds the same tips from recorded facts as from the install input', function (): void {
    registerHandoffPackage('capell-app/theme-foundation', kind: 'theme');
    registerHandoffPackage('capell-app/blog', ['package_blog' => 10]);

    $tips = new InstallHandoffTips;
    $fromInput = $tips->build(
        inputData: installTipsInput(['userId' => 7, 'demoContent' => true, 'selectedThemeKey' => 'capell-app/theme-foundation']),
        selectedPackages: ['capell-app/admin', 'capell-app/blog', 'capell-app/core'],
        completed: true,
        needsFrontendBuild: false,
        adminUrl: 'https://example.test/admin',
        adminEmail: null,
        publicUrl: 'https://example.test/',
        firstPageStatus: 'editable',
    );
    $fromFacts = $tips->buildFor(new InstallHandoffFactsData(
        completed: true,
        needsFrontendBuild: false,
        adminUrl: 'https://example.test/admin',
        adminEmail: null,
        publicUrl: 'https://example.test/',
        firstPageStatus: 'editable',
        demoContent: true,
        existingAccount: true,
        selectedThemeKey: 'capell-app/theme-foundation',
        selectedPackages: ['capell-app/admin', 'capell-app/blog', 'capell-app/core'],
    ));

    expect($fromFacts)->toBe($fromInput)
        ->and(installTipKeys($fromFacts))->toBe(['sign_in', 'first_page', 'theme', 'package:capell-app/blog'])
        ->and($fromFacts[0]['detail'])->toContain('existing administrator account')
        ->and($fromFacts[1]['detail'])->toContain('Demo content');
});

it('treats an install that chose a new administrator as a new account even when a user id is recorded', function (): void {
    $tips = (new InstallHandoffTips)->build(
        inputData: installTipsInput(['userId' => 7, 'newUser' => new NewUserData('Ben', 'ben@example.test', 'hunter2')]),
        selectedPackages: ['capell-app/admin', 'capell-app/core'],
        completed: true,
        needsFrontendBuild: false,
        adminUrl: 'https://example.test/admin',
        adminEmail: null,
        publicUrl: 'https://example.test/',
        firstPageStatus: 'unavailable',
    );

    expect($tips[0]['detail'])->toBe('https://example.test/admin');
});

it('does not claim demo content is seeded when the first page state is unknown or missing', function (string $status): void {
    $tips = (new InstallHandoffTips)->build(
        inputData: installTipsInput(['demoContent' => true]),
        selectedPackages: ['capell-app/admin'],
        completed: true,
        needsFrontendBuild: false,
        adminUrl: 'https://example.test/admin',
        adminEmail: null,
        publicUrl: 'https://example.test/',
        firstPageStatus: $status,
    );

    expect(implode(' ', array_column($tips, 'detail')))->not->toContain('Demo content is seeded');
})->with(['unavailable', 'missing', 'draft']);

it('says a draft homepage is not published yet', function (): void {
    $tips = (new InstallHandoffTips)->build(
        inputData: installTipsInput(),
        selectedPackages: ['capell-app/admin'],
        completed: true,
        needsFrontendBuild: false,
        adminUrl: 'https://example.test/admin',
        adminEmail: null,
        publicUrl: 'https://example.test/',
        firstPageStatus: 'draft',
    );

    expect($tips[1]['key'])->toBe('first_page')
        ->and($tips[1]['detail'])->toContain('not published')
        ->and($tips[1]['detail'])->not->toContain('is published at');
});

it('names the selected theme key when no known package provides it', function (): void {
    $tips = (new InstallHandoffTips)->build(
        inputData: installTipsInput(['selectedThemeKey' => 'bespoke']),
        selectedPackages: ['capell-app/admin'],
        completed: true,
        needsFrontendBuild: false,
        adminUrl: 'https://example.test/admin',
        adminEmail: null,
        publicUrl: 'https://example.test/',
        firstPageStatus: 'editable',
    );

    expect($tips[2]['key'])->toBe('theme')
        ->and($tips[2]['detail'])->toContain('bespoke')
        ->not->toContain('capell-app/theme-bespoke');
});

it('takes content tips from what the selected packages declare, not from their names', function (): void {
    registerHandoffPackage('vendor/recipes', ['package_knowledge_base' => 5]);
    registerHandoffPackage('capell-app/blog');

    $tips = (new InstallHandoffTips)->build(
        inputData: installTipsInput(),
        selectedPackages: ['capell-app/admin', 'capell-app/blog', 'vendor/recipes', 'vendor/unregistered'],
        completed: true,
        needsFrontendBuild: false,
        adminUrl: 'https://example.test/admin',
        adminEmail: null,
        publicUrl: 'https://example.test/',
        firstPageStatus: 'editable',
    );

    expect(installTipKeys($tips))->toBe(['sign_in', 'first_page', 'package:vendor/recipes'])
        ->and($tips[2]['detail'])->toContain('Knowledge');
});

it('ignores a tip variant Core has no copy for instead of failing the handoff', function (): void {
    registerHandoffPackage('vendor/odd', ['package_unknown' => 1, 'package_events' => 2]);

    $tips = (new InstallHandoffTips)->build(
        inputData: installTipsInput(),
        selectedPackages: ['capell-app/admin', 'vendor/odd'],
        completed: true,
        needsFrontendBuild: false,
        adminUrl: 'https://example.test/admin',
        adminEmail: null,
        publicUrl: 'https://example.test/',
        firstPageStatus: 'editable',
    );

    expect(installTipKeys($tips))->toBe(['sign_in', 'first_page', 'package:vendor/odd'])
        ->and($tips[2]['detail'])->toContain('Events');
});
