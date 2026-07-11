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
use Capell\Core\Models\Page;
use Capell\Core\Models\Translation;
use Closure;
use Filament\Schemas\Schema;

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
                    if (! isset($data['translations'][$key][$removeExtra])) {
                        continue;
                    }

                    unset($data['translations'][$key][$removeExtra]);
                }
            }

            $action->replica = ReplicatePageAction::run($record, $data);
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

        $data['name'] = '';

        if ($action->mutateRecordDataUsing instanceof Closure) {
            $data = $action->evaluate($action->mutateRecordDataUsing, ['data' => $data]);
        }

        $record->translations->loadMissing('language');

        $translations = $record->translations->sortBy(fn (Translation $translation): int => $translation->language->default ? 0 : 1);

        if ($translations->isNotEmpty()) {
            $data['translations'] = [];

            foreach ($translations as $translation) {
                $data['translations']['record-' . $translation->id] = $translation->only($translation->getFillable());
            }
        }

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
            ConfiguratorContextData::forEdit(ConfiguratorTypeEnum::Page),
        );
    }
}
