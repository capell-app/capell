<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\ContentGraph;

use Capell\Admin\Data\ContentGraph\DeleteImpactValidationData;
use Capell\Admin\Support\ContentGraph\SharedDeleteImpact;
use Capell\Core\Actions\ContentGraph\BuildContentImpactPreviewAction;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

class ValidateContentDeleteImpactAction
{
    use AsFake;
    use AsObject;

    public function handle(Model $record): DeleteImpactValidationData
    {
        return resolve(SharedDeleteImpact::class)->remember($record, fn (): DeleteImpactValidationData => $this->build($record));
    }

    private function build(Model $record): DeleteImpactValidationData
    {
        $preview = BuildContentImpactPreviewAction::run($record);

        return new DeleteImpactValidationData(
            allowed: ! $preview->blocked,
            blockingCount: $preview->strongCount,
            warningCount: $preview->weakCount,
            preview: $preview,
        );
    }
}
