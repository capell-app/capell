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

it('invalidates numeric surrogate keys without losing other fragment ownership', function (bool $supportsTags): void {
    $directory = sys_get_temp_dir() . '/capell-fragment-test-' . bin2hex(random_bytes(8));
    $files = new Filesystem;
    $repository = new Repository($supportsTags ? new ArrayStore : new FileStore($files, $directory));
    $fragments = new FragmentCache($repository);

    try {
        $repository->put('application-sentinel', 'keep', 3600);
        $fragments->remember('numeric', static fn (): string => 'old', surrogateKeys: ['42', 'page:42']);
        $fragments->remember('zero', static fn (): string => 'old zero', surrogateKeys: ['0']);
        $fragments->remember('other', static fn (): string => 'keep fragment', surrogateKeys: ['page:43']);

        $fragments->invalidateBySurrogateKey('42');
        $fragments->invalidateBySurrogateKey('0');

        expect($fragments->remember('numeric', static fn (): string => 'new'))->toBe('new')
            ->and($fragments->remember('zero', static fn (): string => 'new zero'))->toBe('new zero')
            ->and($fragments->remember('other', static fn (): string => 'unexpected'))->toBe('keep fragment')
            ->and($repository->get('application-sentinel'))->toBe('keep');

        $fragments->invalidateBySurrogateKey('page:42');
        expect($fragments->remember('numeric', static fn (): string => 'unexpected'))->toBe('new');
    } finally {
        $files->deleteDirectory($directory);
    }
})->with(['taggable' => true, 'non-tagging' => false]);
