<?php

declare(strict_types=1);

namespace Capell\Core\Actions\ContentGraph;

use Capell\Core\Data\ContentGraph\ContentImpactReconciliationData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Compare the surfaces shown in an editor impact preview with the surfaces
 * observed after the save and retain the result in the existing activity log.
 *
 * @method static ContentImpactReconciliationData run(Model $target, array<int, string> $predictedSurfaces, array<int, string> $actualSurfaces)
 */
final class ReconcileContentImpactAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  array<int, string>  $predictedSurfaces
     * @param  array<int, string>  $actualSurfaces
     */
    public function handle(Model $target, array $predictedSurfaces, array $actualSurfaces): ContentImpactReconciliationData
    {
        $predicted = $this->normalise($predictedSurfaces);
        $actual = $this->normalise($actualSurfaces);
        $missing = array_values(array_diff($predicted, $actual));
        $unexpected = array_values(array_diff($actual, $predicted));

        $result = new ContentImpactReconciliationData(
            predictedSurfaces: $predicted,
            actualSurfaces: $actual,
            missingSurfaces: $missing,
            unexpectedSurfaces: $unexpected,
            drifted: $missing !== [] || $unexpected !== [],
        );

        $this->recordActivity($target, $result);

        if ($result->drifted) {
            Log::warning('Content impact drift detected after save.', [
                'target_type' => $target::class,
                'target_id' => $target->getKey(),
                'predicted_surfaces' => $result->predictedSurfaces,
                'actual_surfaces' => $result->actualSurfaces,
                'missing_surfaces' => $result->missingSurfaces,
                'unexpected_surfaces' => $result->unexpectedSurfaces,
            ]);
        }

        return $result;
    }

    /**
     * @param  array<int, string>  $surfaces
     * @return list<string>
     */
    private function normalise(array $surfaces): array
    {
        $surfaces = array_values(array_unique(array_filter(
            $surfaces,
            static fn (string $surface): bool => $surface !== '',
        )));
        sort($surfaces);

        return $surfaces;
    }

    private function recordActivity(Model $target, ContentImpactReconciliationData $result): void
    {
        if (! Schema::hasTable('activity_log')) {
            return;
        }

        activity('content-impact')
            ->performedOn($target)
            ->event('reconciled')
            ->withProperties($result->toArray())
            ->log((string) __('capell::generic.content_impact_reconciliation'));
    }
}
