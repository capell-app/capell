<?php

declare(strict_types=1);

use Capell\Frontend\Contracts\Fragments\PublicFragmentReferenceCodec;
use Capell\Frontend\Data\Fragments\PublicFragmentReferenceData;

it('round trips an opaque owner-bound public fragment reference through the real codec', function (): void {
    config()->set('app.key', 'base64:' . base64_encode(str_repeat('r', 32)));
    $codec = resolve(PublicFragmentReferenceCodec::class);
    $reference = new PublicFragmentReferenceData(
        owner: 'layout-builder',
        formatVersion: 1,
        pageableType: 'page',
        pageableId: 4815,
        siteId: 16,
        languageId: 23,
        contentVersion: 'release-contract-v1',
        ownerContext: ['layoutId' => 42, 'widgetKey' => 'hero'],
    );

    $token = $codec->encode($reference);
    $decoded = $codec->decode($token);

    expect($token)->not->toContain('4815', 'layout-builder', 'release-contract-v1')
        ->and($decoded->toArray())->toBe($reference->toArray());
});
