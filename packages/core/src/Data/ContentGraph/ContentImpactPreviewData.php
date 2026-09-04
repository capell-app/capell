<?php

declare(strict_types=1);

namespace Capell\Core\Data\ContentGraph;

use Capell\Core\Support\Impact\ImpactPlanFingerprint;
use Spatie\LaravelData\Data;

final class ContentImpactPreviewData extends Data
{
    public readonly string $fingerprint;

    /**
     * @param  array<int, ContentImpactGroupData>  $groups
     */
    public function __construct(
        public readonly bool $blocked,
        public readonly int $strongCount,
        public readonly int $weakCount,
        public readonly int $informationalCount,
        public readonly array $groups,
        ?string $fingerprint = null,
    ) {
        $this->fingerprint = $fingerprint ?? ImpactPlanFingerprint::forPlan($this->planPayload());
    }

    public function withFingerprint(string $fingerprint): self
    {
        return new self(
            blocked: $this->blocked,
            strongCount: $this->strongCount,
            weakCount: $this->weakCount,
            informationalCount: $this->informationalCount,
            groups: $this->groups,
            fingerprint: $fingerprint,
        );
    }

    /** @return list<string> */
    public function surfaceKeys(): array
    {
        $surfaces = [];

        foreach ($this->groups as $group) {
            foreach ($group->dependencies as $dependency) {
                foreach ($dependency->urls as $url) {
                    $surfaces[] = 'url:' . $url->url;
                }

                if ($dependency->urls === []) {
                    $surfaces[] = 'dependency:' . $dependency->type . '|' . $dependency->site . '|' . $dependency->name;
                }
            }
        }

        sort($surfaces);

        return array_values(array_unique($surfaces));
    }

    /** @return array<string, mixed> */
    public function planPayload(): array
    {
        return [
            'blocked' => $this->blocked,
            'strongCount' => $this->strongCount,
            'weakCount' => $this->weakCount,
            'informationalCount' => $this->informationalCount,
            'groups' => array_map(
                static fn (ContentImpactGroupData $group): array => $group->toArray(),
                $this->groups,
            ),
        ];
    }
}
