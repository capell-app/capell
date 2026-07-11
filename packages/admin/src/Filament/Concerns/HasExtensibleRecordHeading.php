<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Concerns;

use Capell\Admin\Support\Schemas\AdminSchemaExtensionPipeline;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\Support\Htmlable;

/**
 * @mixin EditRecord
 */
trait HasExtensibleRecordHeading
{
    public function getHeading(): string|Htmlable
    {
        return resolve(AdminSchemaExtensionPipeline::class)->editRecordHeading($this, parent::getHeading() ?? '');
    }

    protected function notifyEditRecordHeadingSaved(): void
    {
        resolve(AdminSchemaExtensionPipeline::class)->editRecordSaved($this);
    }
}
