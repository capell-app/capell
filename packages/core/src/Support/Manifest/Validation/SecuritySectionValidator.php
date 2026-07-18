<?php

declare(strict_types=1);

namespace Capell\Core\Support\Manifest\Validation;

use Capell\Core\Contracts\ManifestSectionValidator;
use Capell\Core\Support\Manifest\Exceptions\InvalidManifestException;

final readonly class SecuritySectionValidator implements ManifestSectionValidator
{
    private const array FIELDS = ['riskTier', 'publicSurface', 'sensitiveData', 'publicOutput', 'externalHttpClients', 'adminSurface'];

    public function __construct(private ManifestValidationRules $rules) {}

    public function validate(array $data): void
    {
        if (! array_key_exists('security', $data)) {
            return;
        }

        if (! is_array($data['security'])) {
            throw InvalidManifestException::invalidField('security', 'must be an object');
        }

        $security = $data['security'];

        foreach (array_keys($security) as $field) {
            if (! in_array($field, self::FIELDS, true)) {
                throw InvalidManifestException::invalidField('security.' . $field, 'is not part of manifest v3 security metadata');
            }
        }

        if (array_key_exists('riskTier', $security) && (! is_string($security['riskTier']) || $security['riskTier'] === '')) {
            throw InvalidManifestException::invalidField('security.riskTier', 'must be a non-empty string');
        }

        foreach (['publicSurface', 'sensitiveData', 'publicOutput', 'externalHttpClients', 'adminSurface'] as $field) {
            if (array_key_exists($field, $security) && ! is_array($security[$field])) {
                throw InvalidManifestException::invalidField('security.' . $field, 'must be an object');
            }
        }

        $this->validatePublicSurface($security);
        $this->validateSensitiveData($security);
        $this->validatePublicOutput($security);
        $this->validateExternalHttpClients($security);
        $this->validateAdminSurface($security);
    }

    /** @param array<string, mixed> $security */
    private function validatePublicSurface(array $security): void
    {
        if (! is_array($security['publicSurface'] ?? null)) {
            return;
        }

        foreach (['routeNames', 'csrfExemptRoutes', 'signedRoutes', 'tokenizedRoutes', 'webhookRoutes', 'throttledRoutes'] as $field) {
            $this->rules->stringList($security['publicSurface'], $field, required: false);
        }

        if (array_key_exists('auth', $security['publicSurface']) && (! is_string($security['publicSurface']['auth']) || $security['publicSurface']['auth'] === '')) {
            throw InvalidManifestException::invalidField('security.publicSurface.auth', 'must be a non-empty string');
        }
    }

    /** @param array<string, mixed> $security */
    private function validateSensitiveData(array $security): void
    {
        if (! is_array($security['sensitiveData'] ?? null)) {
            return;
        }

        foreach (['encryptedFields', 'hashedTokenFields', 'redactedOutputClasses', 'plaintextJustifications'] as $field) {
            $this->rules->stringList($security['sensitiveData'], $field, required: false);
        }
    }

    /** @param array<string, mixed> $security */
    private function validatePublicOutput(array $security): void
    {
        if (! is_array($security['publicOutput'] ?? null)) {
            return;
        }

        foreach (['cacheSafe', 'forbidAuthoringSurface', 'forbidSecrets', 'forbidPublicBladeQueries'] as $field) {
            if (array_key_exists($field, $security['publicOutput']) && ! is_bool($security['publicOutput'][$field])) {
                throw InvalidManifestException::invalidField('security.publicOutput.' . $field, 'must be a boolean');
            }
        }
    }

    /** @param array<string, mixed> $security */
    private function validateExternalHttpClients(array $security): void
    {
        if (! is_array($security['externalHttpClients'] ?? null)) {
            return;
        }

        foreach (['requiresTimeouts', 'requiresSecretRedaction'] as $field) {
            if (array_key_exists($field, $security['externalHttpClients']) && ! is_bool($security['externalHttpClients'][$field])) {
                throw InvalidManifestException::invalidField('security.externalHttpClients.' . $field, 'must be a boolean');
            }
        }

        $this->rules->stringList($security['externalHttpClients'], 'clients', required: false);
    }

    /** @param array<string, mixed> $security */
    private function validateAdminSurface(array $security): void
    {
        if (! is_array($security['adminSurface'] ?? null)) {
            return;
        }

        if (array_key_exists('authorization', $security['adminSurface']) && (! is_string($security['adminSurface']['authorization']) || $security['adminSurface']['authorization'] === '')) {
            throw InvalidManifestException::invalidField('security.adminSurface.authorization', 'must be a non-empty string');
        }

        $this->rules->stringList($security['adminSurface'], 'permissions', required: false);
    }
}
