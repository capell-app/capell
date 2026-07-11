<?php

declare(strict_types=1);

namespace Capell\Admin\Actions;

use Capell\Admin\Support\AdminSurfaceLookup;
use Capell\Core\Actions\GetEditPageResourceUrlAction;
use Capell\Core\Enums\BlueprintSubjectEnum;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static string run(string $type, int|Model $record)
 */
class GetAssetResourceUrlAction
{
    use AsObject;

    public function handle(string $type, int|Model $record): ?string
    {
        if ($type === BlueprintSubjectEnum::Page->value) {
            return GetEditPageResourceUrlAction::run($record);
        }

        $resource = AdminSurfaceLookup::resource(ucfirst($type));

        return $resource::getUrl('edit', ['record' => $record]);
    }
}
