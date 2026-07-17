<?php

declare(strict_types=1);

namespace Capell\Admin\Actions;

use Capell\Core\Actions\GenerateUniqueKeyAction;
use Capell\Core\Actions\IncrementNameAction;
use Capell\Core\Models\Layout;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;

/**
 * @method static Layout run(Layout $layout, array<string, mixed> $data = [])
 */
class ReplicateLayoutAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  array<string, mixed>  $data
     * @return Layout
     */
    public function handle(Layout $record, array $data = []): Model
    {
        $model = $record->fresh();
        throw_if($model === null, RuntimeException::class, 'Layout could not be refreshed for replication.');

        $model->fill($data);

        if (! isset($data['name']) || $data['name'] === '') {
            $model->name = $this->getLayoutName($record);
            $model->key = GenerateUniqueKeyAction::run($record);
        }

        $replica = $model->duplicate();
        throw_if($replica === null, RuntimeException::class, 'Layout could not be duplicated.');

        $replica->created_at = CarbonImmutable::now();
        $replica->updated_at = CarbonImmutable::now();

        $replica->save();

        return $replica;
    }

    private function getLayoutName(Layout $layout): string
    {
        $name = IncrementNameAction::run($layout->name);

        /** @var class-string<Layout> $model */
        $model = Layout::class;

        while ($model::query()->where('name', $name)->exists()) {
            $name = IncrementNameAction::run($name);
        }

        return $name;
    }
}
