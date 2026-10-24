<?php

declare(strict_types=1);

use Capell\Core\Data\InstallInputData;
use Capell\Core\Data\NewUserData;
use Capell\Core\Support\Install\InstallHandoffTips;

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

it('asks for a render check when the page exists but is not editable', function (): void {
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
        ->and($tips[1]['detail'])->toContain('https://example.test/');
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
