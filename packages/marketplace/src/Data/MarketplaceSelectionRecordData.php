<?php

declare(strict_types=1);

namespace Capell\Marketplace\Data;

final readonly class MarketplaceSelectionRecordData
{
    public const string FAILURE_BLOCKED = 'blocked';

    public const string FAILURE_INCOMPATIBLE = 'incompatible';

    public const string FAILURE_INSTALL_IN_PROGRESS = 'install_in_progress';

    public const string FAILURE_INSTALLED = 'installed';

    public const string FAILURE_PERMISSION = 'permission';

    public const string FAILURE_UNAVAILABLE = 'unavailable';

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $requiredDependencies
     */
    public function __construct(
        public array $payload,
        public ?string $composerName,
        public string $name,
        public array $requiredDependencies,
        public int $priceCents,
        public bool $requiresPremiumFlow,
        public string $maturity,
        public ?string $failureReasonCode,
    ) {}

    /** @param array<string, mixed> $payload */
    public static function fromPayload(array $payload): self
    {
        $composerName = is_string($payload['composer_name'] ?? null) && $payload['composer_name'] !== ''
            ? $payload['composer_name']
            : null;
        $dependencies = $payload['required_dependencies'] ?? [];

        return new self(
            payload: $payload,
            composerName: $composerName,
            name: is_string($payload['name'] ?? null) && $payload['name'] !== ''
                ? $payload['name']
                : '',
            requiredDependencies: is_array($dependencies)
                ? array_values(array_filter(
                    array_map(
                        static fn (mixed $dependency): ?string => is_string($dependency) && $dependency !== ''
                            ? $dependency
                            : null,
                        $dependencies,
                    ),
                    is_string(...),
                ))
                : [],
            priceCents: is_numeric($payload['price_cents'] ?? null) ? (int) $payload['price_cents'] : 0,
            requiresPremiumFlow: false,
            maturity: is_string($payload['maturity'] ?? null) ? $payload['maturity'] : 'released',
            failureReasonCode: null,
        );
    }

    public function withPolicy(
        bool $requiresPremiumFlow,
        ?string $failureReasonCode,
    ): self {
        return new self(
            payload: $this->payload,
            composerName: $this->composerName,
            name: $this->name,
            requiredDependencies: $this->requiredDependencies,
            priceCents: $this->priceCents,
            requiresPremiumFlow: $requiresPremiumFlow,
            maturity: $this->maturity,
            failureReasonCode: $failureReasonCode,
        );
    }

    public function isSelectable(): bool
    {
        return $this->failureReasonCode === null;
    }

    public function isBeta(): bool
    {
        return $this->maturity === 'beta';
    }

    /**
     * @param  array<string, bool>  $explicitComposerNames
     * @return array<string, mixed>
     */
    public function toImpactArray(array $explicitComposerNames): array
    {
        $impact = is_array($this->payload['install_impact'] ?? null)
            ? $this->payload['install_impact']
            : [];
        $currentVersion = is_string($this->payload['installed_version'] ?? null)
            ? $this->payload['installed_version']
            : null;
        $targetVersion = is_string($this->payload['latest_version'] ?? null)
            ? $this->payload['latest_version']
            : null;
        $composerName = $this->composerName ?? '';
        $isDirect = array_key_exists($composerName, $explicitComposerNames);

        return [
            'composer_name' => $composerName,
            'name' => $this->name,
            'direct' => $isDirect,
            'reason_code' => $isDirect ? 'direct' : 'dependency',
            'maturity' => $this->maturity,
            'entitlement' => is_string($this->payload['entitlement'] ?? null)
                ? $this->payload['entitlement']
                : ($this->requiresPremiumFlow ? 'required' : 'included'),
            'operation' => $currentVersion === null ? 'install' : 'update',
            'current_version' => $currentVersion,
            'target_version' => $targetVersion,
            'migrations' => $this->impactList($impact, 'migrations'),
            'routes' => $this->impactList($impact, 'routes'),
            'scheduled_jobs' => $this->impactList($impact, 'scheduled_jobs'),
            'storage' => $this->impactList($impact, 'storage'),
            'permissions' => $this->impactList($impact, 'permissions'),
        ];
    }

    /**
     * @param  array<string, mixed>  $impact
     * @return list<string>
     */
    private function impactList(array $impact, string $key): array
    {
        $values = $impact[$key] ?? [];

        return is_array($values)
            ? array_values(array_filter(
                $values,
                static fn (mixed $value): bool => is_string($value) && $value !== '',
            ))
            : [];
    }
}
