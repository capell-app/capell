<?php

declare(strict_types=1);

namespace Capell\Core\Data\Workflow;

use Carbon\CarbonImmutable;
use Override;
use Spatie\LaravelData\Data;

final class WorkflowAttentionItemData extends Data
{
    public function __construct(
        public readonly string $packageName,
        public readonly string $label,
        public readonly string $severity,
        public readonly string $owner,
        public readonly string $nextActionLabel,
        public readonly ?string $routeName = null,
        public readonly ?string $url = null,
        public readonly ?string $permission = null,
        public readonly ?CarbonImmutable $staleAt = null,
        public readonly ?int $count = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $staleAt = $data['staleAt'] ?? null;

        return new self(
            packageName: (string) $data['packageName'],
            label: (string) $data['label'],
            severity: (string) $data['severity'],
            owner: (string) $data['owner'],
            nextActionLabel: (string) $data['nextActionLabel'],
            routeName: is_string($data['routeName'] ?? null) ? $data['routeName'] : null,
            url: is_string($data['url'] ?? null) ? $data['url'] : null,
            permission: is_string($data['permission'] ?? null) ? $data['permission'] : null,
            staleAt: $staleAt instanceof CarbonImmutable
                ? $staleAt
                : (is_string($staleAt) && $staleAt !== '' ? CarbonImmutable::parse($staleAt) : null),
            count: is_numeric($data['count'] ?? null) ? (int) $data['count'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return array_filter([
            'packageName' => $this->packageName,
            'label' => $this->label,
            'severity' => $this->severity,
            'owner' => $this->owner,
            'nextActionLabel' => $this->nextActionLabel,
            'routeName' => $this->routeName,
            'url' => $this->url,
            'permission' => $this->permission,
            'staleAt' => $this->staleAt?->toIso8601String(),
            'count' => $this->count,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
