<?php

declare(strict_types=1);

use Capell\Core\Support\PublicRead\PublicArtefactPermit;
use Capell\Frontend\Support\Static\StaticPageArtifactStore;
use Capell\Frontend\Tests\Support\PublishesStaticArtefacts;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    config()->set('capell-frontend.static_artifacts_path', storage_path('framework/testing/owned-static-artifacts'));
});

afterEach(function (): void {
    $store = resolve(StaticPageArtifactStore::class);
    File::delete($store->ownershipPath());
    File::delete($store->ownershipPath() . '.lock');
    File::deleteDirectory($store->root());
});

it('keeps cumulative ownership private when public export receipts are replaced', function (): void {
    $store = resolve(StaticPageArtifactStore::class);
    PublishesStaticArtefacts::write(fn (PublicArtefactPermit $permit) => $store->putOwnedHtml($permit, 'private/index.html', 'Confidential', 10));
    $store->writeManifest(['artifacts' => [['file' => 'private/index.html']]]);
    PublishesStaticArtefacts::write(fn (PublicArtefactPermit $permit) => $store->putOwnedHtml($permit, 'public/index.html', 'Public', 20));

    $receipt = ['artifacts' => [['file' => 'public/index.html']]];
    $store->writeManifest($receipt);

    expect(str_starts_with($store->ownershipPath(), $store->root() . '/'))->toBeFalse()
        ->and(fileperms($store->ownershipPath()) & 0777)->toBe(0600)
        ->and(json_decode(File::get($store->ownershipPath()), true, 512, JSON_THROW_ON_ERROR)['files'])->toBe(['private/index.html' => [10], 'public/index.html' => [20]])
        ->and($store->readManifest())->toBe($receipt);

    $store->purgeSite(10);

    expect(File::exists($store->root() . '/private/index.html'))->toBeFalse()
        ->and(File::get($store->root() . '/public/index.html'))->toBe('Public')
        ->and($store->readManifest())->toBe($receipt);
});

it('does not publish HTML if private inventory publication fails', function (): void {
    $store = resolve(StaticPageArtifactStore::class);
    File::partialMock()->shouldReceive('move')->once()->with(Mockery::type('string'), $store->ownershipPath())->andReturn(false);

    expect(fn (): bool => PublishesStaticArtefacts::write(fn (PublicArtefactPermit $permit) => $store->putOwnedHtml($permit, 'private/index.html', 'Confidential', 10)))->toThrow(RuntimeException::class)
        ->and(File::exists($store->root() . '/private/index.html'))->toBeFalse();
});

it('retains former ownership when a replacement HTML publication fails', function (): void {
    $store = resolve(StaticPageArtifactStore::class);
    PublishesStaticArtefacts::write(fn (PublicArtefactPermit $permit) => $store->putOwnedHtml($permit, 'shared/index.html', 'Former confidential bytes', 10));
    File::partialMock()->shouldReceive('move')->once()->with(Mockery::type('string'), $store->root() . '/shared/index.html')->andReturn(false);

    expect(fn (): bool => PublishesStaticArtefacts::write(fn (PublicArtefactPermit $permit) => $store->putOwnedHtml($permit, 'shared/index.html', 'New public bytes', 20)))->toThrow(RuntimeException::class)
        ->and(File::get($store->root() . '/shared/index.html'))->toBe('Former confidential bytes');

    $store->purgeSite(10);

    expect(File::exists($store->root() . '/shared/index.html'))->toBeFalse();
});

it('gates repair on corrupt ownership rather than silently retaining confidential files', function (): void {
    $store = resolve(StaticPageArtifactStore::class);
    PublishesStaticArtefacts::write(fn (PublicArtefactPermit $permit) => $store->putOwnedHtml($permit, 'private/index.html', 'Confidential', 10));
    File::put($store->ownershipPath(), '{broken');

    expect(fn () => $store->purgeSite(10))->toThrow(JsonException::class)
        ->and(File::get($store->root() . '/private/index.html'))->toBe('Confidential');
});

it('removes untracked legacy HTML beyond the current receipt', function (): void {
    $store = resolve(StaticPageArtifactStore::class);
    PublishesStaticArtefacts::write(fn (PublicArtefactPermit $permit) => $store->putHtml($permit, 'old-host/removed-route/index.html', 'Confidential orphan'));
    PublishesStaticArtefacts::write(fn (PublicArtefactPermit $permit) => $store->putHtml($permit, 'latest/index.html', 'Unattributed latest export'));
    PublishesStaticArtefacts::write(fn (PublicArtefactPermit $permit) => $store->putHtml($permit, '.git/hidden/index.html', 'Confidential hidden export'));
    $store->writeManifest(['artifacts' => [['file' => 'latest/index.html'], ['file' => 'already-invalidated/index.html']]]);

    $store->purgeSite(10);

    expect(File::exists($store->root() . '/old-host/removed-route/index.html'))->toBeFalse()
        ->and(File::exists($store->root() . '/latest/index.html'))->toBeFalse()
        ->and(File::exists($store->root() . '/.git/hidden/index.html'))->toBeFalse()
        ->and($store->readManifest()['artifacts'])->toBe([]);
});
