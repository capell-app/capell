<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Pages;

use Capell\Core\Actions\ResolvePageableMorphModelAction;
use Capell\Core\Contracts\Pageable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class ResolveContentLockRecordAction
{
    use AsFake;
    use AsObject;

    /** @phpstan-return (Model&Pageable<Model>)|null */
    public function handle(string $type, string $recordId): (Model&Pageable)|null
    {
        $modelClass = Relation::getMorphedModel($type);
        if ($modelClass === null || ! is_subclass_of($modelClass, Pageable::class)) {
            return null;
        }

        $record = ResolvePageableMorphModelAction::run($type, $recordId);

        return $record instanceof Pageable ? $record : null;
    }
}
