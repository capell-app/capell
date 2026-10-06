<?php

declare(strict_types=1);

use Capell\Core\Data\InstallInputData;
use Capell\Core\Data\NewUserData;
use Capell\Core\Facades\CapellCore;
use Capell\Installer\Actions\ReviewInstallerInputAction;

function reviewTestInput(string $url = 'https://example.test'): InstallInputData
{
    return new InstallInputData(
        siteUrl: $url,
        packages: [],
        languages: ['en'],
        demoContent: false,
        cachesToClear: [],
        generateSitemap: false,
        generateStaticSite: false,
        newUser: new NewUserData(name: 'Admin', email: 'review@example.test', password: 'secret-test-password'),
    );
}

it('accepts only the reviewed input and never returns credentials', function (): void {
    CapellCore::clearPackages();
    $action = resolve(ReviewInstallerInputAction::class);
    $input = reviewTestInput();
    $review = $action->handle($input);
    expect(json_encode($review, JSON_THROW_ON_ERROR))->not->toContain('secret-test-password')
        ->and($review['items']['Administrator'])->toContain('review@example.test')
        ->and($action->accepts($input, $review['token']))->toBeTrue()
        ->and($action->accepts(reviewTestInput('https://changed.test'), $review['token']))->toBeFalse()
        ->and($action->accepts($input, 'invalid-token'))->toBeFalse()
        ->and($action->accepts($input, $review['token'], true))->toBeFalse()
        ->and($action->accepts($input, $review['token'], false, 'other-session'))->toBeFalse();
});

it('requires a new review once acceptance expires', function (): void {
    CapellCore::clearPackages();
    $action = resolve(ReviewInstallerInputAction::class);
    $input = reviewTestInput();
    $review = $action->handle($input);
    $this->travel(31)->minutes();
    try {
        expect($action->accepts($input, $review['token']))->toBeFalse();
    } finally {
        $this->travelBack();
    }
});
