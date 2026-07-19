<?php

declare(strict_types=1);

use Capell\Core\Actions\FetchSiteSpecMediaAction;
use Capell\Core\Data\SiteSpec\CapellSiteSpecMediaData;
use Capell\Core\Enums\MediaCollectionEnum;
use Capell\Core\Support\CapellSiteSpecConstraints;
use Illuminate\Support\Facades\Http;

function siteSpecPng(): string
{
    $image = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9Z3h8AAAAASUVORK5CYII=', true);

    throw_unless(is_string($image), RuntimeException::class, 'Unable to decode the site spec PNG fixture.');

    return $image;
}

it('downloads only same-origin public images within the configured budgets', function (): void {
    $image = siteSpecPng();
    Http::fake([
        'https://93.184.216.34/*' => Http::response($image, 200, [
            'Content-Type' => 'image/png',
            'Content-Length' => (string) strlen($image),
        ]),
    ]);

    $downloads = FetchSiteSpecMediaAction::run(new CapellSiteSpecMediaData(
        sourceUrl: 'https://93.184.216.34',
        logo: 'https://93.184.216.34/logo.png',
        images: ['home' => 'https://93.184.216.34/home.png'],
    ));

    try {
        expect($downloads)->toHaveCount(2)
            ->and($downloads[0]->collection)->toBe(MediaCollectionEnum::Logo)
            ->and($downloads[1]->collection)->toBe(MediaCollectionEnum::Image)
            ->and($downloads[1]->pageSlug)->toBe('home')
            ->and(file_get_contents($downloads[0]->path))->toBe($image);
    } finally {
        resolve(FetchSiteSpecMediaAction::class)->deleteDownloads($downloads);
    }

    Http::assertSentCount(2);
});

it('refuses media hosts that resolve to non-public addresses before sending a request', function (): void {
    Http::fake();

    expect(fn (): array => FetchSiteSpecMediaAction::run(new CapellSiteSpecMediaData(
        sourceUrl: 'https://127.0.0.1',
        logo: 'https://127.0.0.1/logo.png',
    )))->toThrow(RuntimeException::class, 'non-public address');

    Http::assertNothingSent();
});

it('refuses shared address space that is not globally routable', function (): void {
    Http::fake();

    expect(fn (): array => FetchSiteSpecMediaAction::run(new CapellSiteSpecMediaData(
        sourceUrl: 'https://100.64.0.1',
        logo: 'https://100.64.0.1/logo.png',
    )))->toThrow(RuntimeException::class, 'non-public address');

    Http::assertNothingSent();
});

it('refuses media outside the declared source origin', function (): void {
    Http::fake();

    expect(fn (): array => FetchSiteSpecMediaAction::run(new CapellSiteSpecMediaData(
        sourceUrl: 'https://93.184.216.34',
        logo: 'https://1.1.1.1/logo.png',
    )))->toThrow(RuntimeException::class, 'outside the declared source origin');

    Http::assertNothingSent();
});

it('rejects declared media larger than the per-file budget', function (): void {
    Http::fake([
        'https://93.184.216.34/*' => Http::response(siteSpecPng(), 200, [
            'Content-Type' => 'image/png',
            'Content-Length' => (string) (CapellSiteSpecConstraints::MAX_MEDIA_FILE_BYTES + 1),
        ]),
    ]);

    expect(fn (): array => FetchSiteSpecMediaAction::run(new CapellSiteSpecMediaData(
        sourceUrl: 'https://93.184.216.34',
        logo: 'https://93.184.216.34/logo.png',
    )))->toThrow(RuntimeException::class, 'exceeds the allowed download size');
});

it('rejects image responses whose declared and detected media types differ', function (): void {
    Http::fake([
        'https://93.184.216.34/*' => Http::response(siteSpecPng(), 200, [
            'Content-Type' => 'image/jpeg',
        ]),
    ]);

    try {
        FetchSiteSpecMediaAction::run(new CapellSiteSpecMediaData(
            sourceUrl: 'https://93.184.216.34',
            logo: 'https://93.184.216.34/logo.png?signature=secret',
        ));
    } catch (RuntimeException $runtimeException) {
        expect($runtimeException->getMessage())->toContain('mismatched image content')
            ->not->toContain('signature')
            ->not->toContain('secret');

        return;
    }

    $this->fail('Expected the mismatched media type to be rejected.');
});
