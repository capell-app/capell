<?php

declare(strict_types=1);

namespace Capell\Core\Data\Marketplace;

use Capell\Core\Enums\ExtensionLicenceStatus;
use Override;
use Spatie\LaravelData\Data;

final class ExtensionLicenceDecisionData extends Data
{
    /**
     * @param  array<string, mixed>  $signedActivation
     */
    public function __construct(
        public readonly ExtensionLicenceStatus $licenceStatus,
        public readonly bool $canViewPrivateDocs,
        public readonly bool $canDownload,
        public readonly bool $canInstall,
        public readonly bool $canUpdate,
        public readonly bool $canRate,
        public readonly bool $canComment,
        public readonly bool $runtimeAllowed,
        public readonly ?string $reason = null,
        public readonly ?string $verifiedSiteId = null,
        public readonly ?string $installId = null,
        public readonly array $signedActivation = [],
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromApiResponse(array $payload): self
    {
        return new self(
            licenceStatus: ExtensionLicenceStatus::tryFrom((string) ($payload['licence_status'] ?? 'none')) ?? ExtensionLicenceStatus::None,
            canViewPrivateDocs: (bool) ($payload['can_view_private_docs'] ?? false),
            canDownload: (bool) ($payload['can_download'] ?? false),
            canInstall: (bool) ($payload['can_install'] ?? false),
            canUpdate: (bool) ($payload['can_update'] ?? false),
            canRate: (bool) ($payload['can_rate'] ?? false),
            canComment: (bool) ($payload['can_comment'] ?? false),
            runtimeAllowed: (bool) ($payload['runtime_allowed'] ?? false),
            reason: self::optionalString($payload['reason'] ?? null),
            verifiedSiteId: self::optionalString($payload['verified_site_id'] ?? null),
            installId: self::optionalString($payload['install_id'] ?? null),
            signedActivation: is_array($payload['signed_activation'] ?? null) ? $payload['signed_activation'] : [],
        );
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'licence_status' => $this->licenceStatus->value,
            'can_view_private_docs' => $this->canViewPrivateDocs,
            'can_download' => $this->canDownload,
            'can_install' => $this->canInstall,
            'can_update' => $this->canUpdate,
            'can_rate' => $this->canRate,
            'can_comment' => $this->canComment,
            'runtime_allowed' => $this->runtimeAllowed,
            'reason' => $this->reason,
            'verified_site_id' => $this->verifiedSiteId,
            'install_id' => $this->installId,
            'signed_activation' => $this->signedActivation,
        ];
    }

    private static function optionalString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $stringValue = (string) $value;

        return $stringValue !== '' ? $stringValue : null;
    }
}
