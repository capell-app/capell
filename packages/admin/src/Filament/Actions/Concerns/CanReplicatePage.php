<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Actions\Concerns;

use Capell\Admin\Actions\ReplicatePageAction;
use Capell\Admin\Data\Configurators\ConfiguratorContextData;
use Capell\Admin\Enums\ConfiguratorTypeEnum;
use Capell\Admin\Filament\Actions;
use Capell\Admin\Filament\Configurators\Pages\DefaultPageConfigurator;
use Capell\Admin\Support\Configurators\ConfiguratorResolver;
use Capell\Admin\Support\Filament\RawState;
use Capell\Core\Exceptions\PageUrlCollisionException;
use Capell\Core\Models\Page;
use Closure;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;

trait CanReplicatePage
{
    protected function replicatePageAction(Actions\Page\ReplicatePageAction|Actions\Table\ReplicatePageAction $action, Schema $schema): mixed
    {
        $result = $action->process(function (Page $record, array $data) use ($action, $schema): void {
            $rawState = RawState::array($schema->getRawState());
            $data['translations'] = is_array($rawState['translations'] ?? null) ? $rawState['translations'] : [];

            $removeExtras = ['slug_auto_update_disabled'];
            foreach (array_keys($data['translations']) as $key) {
                foreach ($removeExtras as $removeExtra) {
                    unset($data['translations'][$key][$removeExtra]);
                }
            }

            try {
                $action->replica = ReplicatePageAction::run($record, $data);
            } catch (PageUrlCollisionException $pageUrlCollisionException) {
                foreach ($data['translations'] as $key => $translation) {
                    if ((int) $translation['language_id'] !== $pageUrlCollisionException->languageId) {
                        continue;
                    }

                    $slugLabel = __('capell-admin::form.slug');

                    throw ValidationException::withMessages([
                        $schema->getStatePath() . '.translations.' . $key . '.meta.slug' => __('validation.unique', ['attribute' => $slugLabel]),
                    ]);
                }

                throw $pageUrlCollisionException;
            }
        });

        try {
            return $result;
        } finally {
            $action->success();
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function replicatePageFillForm(Actions\Page\ReplicatePageAction|Actions\Table\ReplicatePageAction $action, Page $record): array
    {
        $data = $record->only($record->getFillable());

        $defaults = resolve(ReplicatePageAction::class)->getDefaults($record);
        $data['name'] = $defaults['name'];

        if ($action->mutateRecordDataUsing instanceof Closure) {
            $data = $action->evaluate($action->mutateRecordDataUsing, ['data' => $data]);
        }

        $data['translations'] = $defaults['translations'];

        return $data;
    }

    protected function replicatePageSchema(Schema $schema, Page $record): Schema
    {
        $configurator = resolve(ConfiguratorResolver::class)->resolveForRecord(
            $record,
            ConfiguratorTypeEnum::Page,
            DefaultPageConfigurator::getKey(),
        );

        return $configurator::configure(
            $schema->operation('replicate'),
            new ConfiguratorContextData(target: ConfiguratorTypeEnum::Page, operation: 'replicate'),
        );
    }
}
