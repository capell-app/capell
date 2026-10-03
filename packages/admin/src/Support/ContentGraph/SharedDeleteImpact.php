<?php

declare(strict_types=1);

namespace Capell\Admin\Support\ContentGraph;

use Capell\Admin\Data\ContentGraph\DeleteImpactValidationData;
use Closure;
use Illuminate\Database\Eloquent\Model;

/** Share a preview only inside one validation, never across mutations or selections. */
final class SharedDeleteImpact
{
    private ?Model $record = null;

    private ?DeleteImpactValidationData $result = null;

    /** @param Closure(): bool $validate */
    public function during(Model $record, Closure $validate): bool
    {
        $previousRecord = $this->record;
        $previousResult = $this->result;
        $this->record = $record;
        $this->result = null;

        try {
            return $validate();
        } finally {
            $this->record = $previousRecord;
            $this->result = $previousResult;
        }
    }

    /** @param Closure(): DeleteImpactValidationData $build */
    public function remember(Model $record, Closure $build): DeleteImpactValidationData
    {
        if ($this->record !== $record) {
            return $build();
        }

        return $this->result ??= $build();
    }
}
