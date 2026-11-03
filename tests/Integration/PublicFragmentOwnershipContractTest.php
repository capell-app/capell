<?php

declare(strict_types=1);

use Capell\Frontend\Contracts\Fragments\PublicFragmentReferenceCodec;
use Capell\Frontend\Contracts\Fragments\PublicFragmentUrlResolver;
use Capell\Frontend\Data\Fragments\PublicFragmentReferenceData;
use Capell\Frontend\Support\Fragments\PublicFragmentUrlResolverRegistry;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Assert;

it('exposes only the owner-aware public fragment protocol', function (): void {
    $legacyContract = implode('', ['Deferred', 'Fragment', 'Reference', 'Builder']);
    $legacyClass = 'Capell\\Frontend\\Contracts\\' . $legacyContract;

    expect(interface_exists($legacyClass))->toBeFalse()
        ->and(interface_exists(PublicFragmentReferenceCodec::class))->toBeTrue()
        ->and(interface_exists(PublicFragmentUrlResolver::class))->toBeTrue()
        ->and(class_exists(PublicFragmentReferenceData::class))->toBeTrue()
        ->and(app()->bound(PublicFragmentUrlResolverRegistry::class))->toBeTrue();

    $root = dirname(__DIR__, 2);
    $paths = [
        $root . '/packages/frontend/src',
        $root . '/packages/admin/src',
        $root . '/docs/frontend',
        $root . '/docs/performance',
    ];

    foreach ($paths as $path) {
        foreach (File::allFiles($path) as $file) {
            Assert::assertStringNotContainsString($legacyContract, File::get($file->getPathname()), $file->getPathname());
        }
    }
});
