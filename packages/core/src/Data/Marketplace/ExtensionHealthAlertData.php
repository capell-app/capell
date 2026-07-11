<?php

declare(strict_types=1);

namespace Capell\Core\Data\Marketplace;

use Capell\Core\Enums\ExtensionHealthAlertCategory;
use Capell\Core\Enums\ExtensionHealthAlertSeverity;
use Override;
use Spatie\LaravelData\Data;

final class ExtensionHealthAlertData extends Data
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $alertId,
        public readonly ?string $extensionSlug,
        public readonly ?string $composerName,
        public readonly ?string $siteId,
        public readonly ?string $installId,
        public readonly ExtensionHealthAlertSeverity $severity,
        public readonly ExtensionHealthAlertCategory $category,
        public readonly string $title,
        public readonly string $message,
        public readonly ?string $requiredAction,
        public readonly bool $runtimeDisabled,
        public readonly bool $protectedActionsBlocked,
        public readonly ?string $issuedAt,
        public readonly ?string $expiresAt,
        public readonly ?string $signature,
        public readonly array $payload,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromApiResponse(array $payload): self
    {
        return new self(
            alertId: self::stringValue($payload['alert_id'] ?? ''),
            extensionSlug: self::optionalString($payload['extension_slug'] ?? null),
            composerName: self::optionalString($payload['composer_name'] ?? null),
            siteId: self::optionalString($payload['site_id'] ?? null),
            installId: self::optionalString($payload['install_id'] ?? null),
            severity: ExtensionHealthAlertSeverity::tryFrom((string) ($payload['severity'] ?? 'info')) ?? ExtensionHealthAlertSeverity::Info,
            category: ExtensionHealthAlertCategory::tryFrom((string) ($payload['category'] ?? 'package')) ?? ExtensionHealthAlertCategory::Package,
            title: self::stringValue($payload['title'] ?? ''),
            message: self::stringValue($payload['message'] ?? ''),
            requiredAction: self::optionalString($payload['required_action'] ?? null),
            runtimeDisabled: (bool) ($payload['runtime_disabled'] ?? false),
            protectedActionsBlocked: (bool) ($payload['protected_actions_blocked'] ?? false),
            issuedAt: self::optionalString($payload['issued_at'] ?? null),
            expiresAt: self::optionalString($payload['expires_at'] ?? null),
            signature: self::optionalString($payload['signature'] ?? null),
            payload: $payload,
        );
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return array_merge($this->payload, [
            'alert_id' => $this->alertId,
            'extension_slug' => $this->extensionSlug,
            'composer_name' => $this->composerName,
            'site_id' => $this->siteId,
            'install_id' => $this->installId,
            'severity' => $this->severity->value,
            'category' => $this->category->value,
            'title' => $this->title,
            'message' => $this->message,
            'required_action' => $this->requiredAction,
            'runtime_disabled' => $this->runtimeDisabled,
            'protected_actions_blocked' => $this->protectedActionsBlocked,
            'issued_at' => $this->issuedAt,
            'expires_at' => $this->expiresAt,
            'signature' => $this->signature,
        ]);
    }

    private static function optionalString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $stringValue = (string) $value;

        return $stringValue !== '' ? $stringValue : null;
    }

    private static function stringValue(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
