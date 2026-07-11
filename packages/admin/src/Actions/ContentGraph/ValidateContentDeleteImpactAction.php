<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\ContentGraph;

use Capell\Admin\Data\ContentGraph\DeleteImpactValidationData;
use Capell\Core\Actions\ContentGraph\BuildContentImpactPreviewAction;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsObject;

class ValidateContentDeleteImpactAction
{
    use AsObject;

    public function handle(Model $record): DeleteImpactValidationData
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
