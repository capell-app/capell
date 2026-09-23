<?php

declare(strict_types=1);

use Capell\Frontend\Support\Cache\FragmentCache;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\FileStore;
use Illuminate\Cache\Repository;
use Illuminate\Filesystem\Filesystem;

it('flushes only fragments including fragments without surrogate keys', function (bool $supportsTags): void {
    $directory = sys_get_temp_dir() . '/capell-fragment-test-' . bin2hex(random_bytes(8));
    $files = new Filesystem;
    $repository = new Repository($supportsTags ? new ArrayStore : new FileStore($files, $directory));
    $fragments = new FragmentCache($repository);

    try {
        $repository->put('application-sentinel', 'keep', 3600);
        $fragments->remember('plain', static fn (): string => 'old plain');
        $fragments->remember('tagged', static fn (): string => 'old tagged', surrogateKeys: ['page:1']);
        $fragments->flush();

        expect($repository->get('application-sentinel'))->toBe('keep')
            ->and($fragments->remember('plain', static fn (): string => 'new plain'))->toBe('new plain')
            ->and($fragments->remember('tagged', static fn (): string => 'new tagged', surrogateKeys: ['page:1']))->toBe('new tagged');
    } finally {
        $files->deleteDirectory($directory);
    }
})->with(['taggable' => true, 'non-tagging' => false]);

it('removes surrogate ownership when fragments are invalidated or flushed', function (): void {
    $repository = new Repository(new ArrayStore);
    $fragments = new FragmentCache($repository);
    $fragments->remember('shared', static fn (): string => 'old', surrogateKeys: ['page:1', 'page:2']);
    $fragments->invalidateBySurrogateKey('page:1');
    $fragments->remember('shared', static fn (): string => 'new');
    $fragments->invalidateBySurrogateKey('page:2');

    expect($fragments->remember('shared', static fn (): string => 'unexpected'))->toBe('new');

    $fragments->flush();
    $fragments->remember('shared', static fn (): string => 'after flush');
    $fragments->invalidateBySurrogateKey('page:1');

    expect($fragments->remember('shared', static fn (): string => 'unexpected'))->toBe('after flush');
});
