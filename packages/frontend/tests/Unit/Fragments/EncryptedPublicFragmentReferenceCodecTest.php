<?php

declare(strict_types=1);

use Capell\Frontend\Contracts\Fragments\PublicFragmentReferenceCodec;
use Capell\Frontend\Data\Fragments\PublicFragmentReferenceData;
use Capell\Frontend\Exceptions\PublicFragmentReferenceInvalid;
use Capell\Frontend\Support\Fragments\EncryptedPublicFragmentReferenceCodec;
use Illuminate\Support\Facades\Crypt;

function encryptedPublicFragmentPayload(array|string $payload): string
{
    $json = is_string($payload) ? $payload : json_encode($payload, JSON_THROW_ON_ERROR);

    return rtrim(strtr(Crypt::encryptString($json), '+/', '-_'), '=');
}

function validPublicFragmentPayload(array $overrides = []): array
{
    return [
        'owner' => 'layout-builder',
        'formatVersion' => 1,
        'pageableType' => 'page',
        'pageableId' => 41,
        'siteId' => 7,
        'languageId' => 3,
        'contentVersion' => 'sha256:current-content',
        'ownerContext' => [
            'layoutId' => 19,
            'widgetKey' => 'hero',
        ],
        ...$overrides,
    ];
}

it('round trips a typed public fragment reference', function (): void {
    $reference = new PublicFragmentReferenceData(
        owner: 'layout-builder',
        formatVersion: 1,
        pageableType: 'page',
        pageableId: 41,
        siteId: 7,
        languageId: 3,
        contentVersion: 'sha256:current-content',
        ownerContext: [
            'layoutId' => 19,
            'widgetKey' => 'hero',
        ],
    );

    $codec = resolve(PublicFragmentReferenceCodec::class);
    $token = $codec->encode($reference);

    expect($token)->not->toContain('layout-builder', 'current-content', 'widgetKey')
        ->and($codec->decode($token))->toEqual($reference);
});

it('binds the shared codec contract to the encrypted implementation', function (): void {
    expect(resolve(PublicFragmentReferenceCodec::class))
        ->toBeInstanceOf(EncryptedPublicFragmentReferenceCodec::class);
});

it('rejects malformed ciphertext without exposing the supplied token', function (): void {
    $token = 'not-a-valid-public-fragment-reference';

    try {
        resolve(EncryptedPublicFragmentReferenceCodec::class)->decode($token);
    } catch (PublicFragmentReferenceInvalid $publicFragmentReferenceInvalid) {
        expect($publicFragmentReferenceInvalid->getMessage())
            ->toBe('Public fragment reference is invalid.')
            ->not->toContain($token);

        return;
    }

    $this->fail('Expected an invalid public fragment reference exception.');
});

it('rejects invalid decrypted json', function (): void {
    expect(fn (): PublicFragmentReferenceData => resolve(EncryptedPublicFragmentReferenceCodec::class)
        ->decode(encryptedPublicFragmentPayload('{not-json')))
        ->toThrow(PublicFragmentReferenceInvalid::class, 'Public fragment reference is invalid.');
});

it('rejects missing and unexpected envelope fields', function (array $payload): void {
    expect(fn (): PublicFragmentReferenceData => resolve(EncryptedPublicFragmentReferenceCodec::class)
        ->decode(encryptedPublicFragmentPayload($payload)))
        ->toThrow(PublicFragmentReferenceInvalid::class, 'Public fragment reference is invalid.');
})->with([
    'missing owner' => fn (): array => array_diff_key(validPublicFragmentPayload(), ['owner' => true]),
    'unexpected field' => fn (): array => validPublicFragmentPayload(['modelClass' => 'App\\Models\\Page']),
]);

it('rejects unsupported format versions', function (): void {
    expect(fn (): PublicFragmentReferenceData => resolve(EncryptedPublicFragmentReferenceCodec::class)
        ->decode(encryptedPublicFragmentPayload(validPublicFragmentPayload(['formatVersion' => 2]))))
        ->toThrow(PublicFragmentReferenceInvalid::class, 'Public fragment reference is invalid.');
});

it('rejects non scalar owner context values', function (mixed $invalidValue): void {
    $payload = validPublicFragmentPayload([
        'ownerContext' => ['layoutId' => 19, 'invalid' => $invalidValue],
    ]);

    expect(fn (): PublicFragmentReferenceData => resolve(EncryptedPublicFragmentReferenceCodec::class)
        ->decode(encryptedPublicFragmentPayload($payload)))
        ->toThrow(PublicFragmentReferenceInvalid::class, 'Public fragment reference is invalid.');
})->with([
    'array' => [['nested' => true]],
    'boolean' => [true],
    'null' => [null],
    'float' => [1.5],
]);

it('rejects empty identity values', function (array $overrides): void {
    expect(fn (): PublicFragmentReferenceData => resolve(EncryptedPublicFragmentReferenceCodec::class)
        ->decode(encryptedPublicFragmentPayload(validPublicFragmentPayload($overrides))))
        ->toThrow(PublicFragmentReferenceInvalid::class, 'Public fragment reference is invalid.');
})->with([
    'owner' => [['owner' => '']],
    'pageable type' => [['pageableType' => '']],
    'pageable id' => [['pageableId' => '']],
    'site id' => [['siteId' => '']],
    'language id' => [['languageId' => '']],
    'content version' => [['contentVersion' => '']],
]);
