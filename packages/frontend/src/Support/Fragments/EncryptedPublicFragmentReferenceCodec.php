<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Fragments;

use Capell\Frontend\Contracts\Fragments\PublicFragmentReferenceCodec;
use Capell\Frontend\Data\Fragments\PublicFragmentReferenceData;
use Capell\Frontend\Exceptions\PublicFragmentReferenceInvalid;
use Illuminate\Support\Facades\Crypt;
use Throwable;

final class EncryptedPublicFragmentReferenceCodec implements PublicFragmentReferenceCodec
{
    public const int CURRENT_FORMAT_VERSION = 1;

    private const array REQUIRED_KEYS = [
        'owner',
        'formatVersion',
        'pageableType',
        'pageableId',
        'siteId',
        'languageId',
        'contentVersion',
        'ownerContext',
    ];

    public function encode(PublicFragmentReferenceData $reference): string
    {
        $payload = [
            'owner' => $reference->owner,
            'formatVersion' => $reference->formatVersion,
            'pageableType' => $reference->pageableType,
            'pageableId' => $reference->pageableId,
            'siteId' => $reference->siteId,
            'languageId' => $reference->languageId,
            'contentVersion' => $reference->contentVersion,
            'ownerContext' => $reference->ownerContext,
        ];

        try {
            $this->assertValidPayload($payload);

            return $this->toUrlSafeToken(Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)));
        } catch (Throwable) {
            throw new PublicFragmentReferenceInvalid;
        }
    }

    public function decode(string $token): PublicFragmentReferenceData
    {
        try {
            $payload = json_decode(
                Crypt::decryptString($this->fromUrlSafeToken($token)),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            if (! is_array($payload)) {
                throw new PublicFragmentReferenceInvalid;
            }

            $this->assertValidPayload($payload);

            /** @var array<string, int|string> $ownerContext */
            $ownerContext = $payload['ownerContext'];

            return new PublicFragmentReferenceData(
                owner: $payload['owner'],
                formatVersion: $payload['formatVersion'],
                pageableType: $payload['pageableType'],
                pageableId: $payload['pageableId'],
                siteId: $payload['siteId'],
                languageId: $payload['languageId'],
                contentVersion: $payload['contentVersion'],
                ownerContext: $ownerContext,
            );
        } catch (Throwable) {
            throw new PublicFragmentReferenceInvalid;
        }
    }

    /**
     * @param  array<mixed>  $payload
     */
    private function assertValidPayload(array $payload): void
    {
        if (count($payload) !== count(self::REQUIRED_KEYS)
            || array_diff(self::REQUIRED_KEYS, array_keys($payload)) !== []
            || array_diff(array_keys($payload), self::REQUIRED_KEYS) !== []) {
            throw new PublicFragmentReferenceInvalid;
        }

        if (! is_string($payload['owner'])
            || preg_match('/^[a-z0-9][a-z0-9._-]*$/', $payload['owner']) !== 1
            || $payload['formatVersion'] !== self::CURRENT_FORMAT_VERSION
            || ! is_string($payload['pageableType'])
            || trim($payload['pageableType']) === ''
            || ! $this->isValidIdentifier($payload['pageableId'])
            || ! $this->isValidIdentifier($payload['siteId'])
            || ! $this->isValidIdentifier($payload['languageId'])
            || ! is_string($payload['contentVersion'])
            || trim($payload['contentVersion']) === ''
            || ! is_array($payload['ownerContext'])) {
            throw new PublicFragmentReferenceInvalid;
        }

        foreach ($payload['ownerContext'] as $key => $value) {
            if (! is_string($key)
                || trim($key) === ''
                || (! is_int($value) && ! is_string($value))) {
                throw new PublicFragmentReferenceInvalid;
            }
        }
    }

    private function isValidIdentifier(mixed $identifier): bool
    {
        return (is_int($identifier) && $identifier > 0)
            || (is_string($identifier) && trim($identifier) !== '');
    }

    private function toUrlSafeToken(string $encryptedReference): string
    {
        return rtrim(strtr($encryptedReference, '+/', '-_'), '=');
    }

    private function fromUrlSafeToken(string $token): string
    {
        if ($token === '') {
            throw new PublicFragmentReferenceInvalid;
        }

        $encryptedReference = strtr($token, '-_', '+/');

        return $encryptedReference . str_repeat('=', (4 - strlen($encryptedReference) % 4) % 4);
    }
}
