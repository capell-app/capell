<?php

declare(strict_types=1);

namespace Capell\Core\Support\Makers\BuiltIn;

use Capell\Core\Actions\Structure\CreateLayoutAction;
use Capell\Core\Contracts\Makers\Maker;
use Capell\Core\Data\Makers\MakerDatabaseRecordData;
use Capell\Core\Data\Makers\MakerDefinitionData;
use Capell\Core\Data\Makers\MakerInputData;
use Capell\Core\Data\Makers\MakerPreviewData;
use Capell\Core\Data\Makers\MakerResultData;
use Capell\Core\Models\Layout;
use Illuminate\Support\Str;
use RuntimeException;

final class LayoutMaker implements Maker
{
    public function definition(): MakerDefinitionData
    {
        return new MakerDefinitionData('core.layout', 'Layout', 'Create a layout, optionally with the containers of an existing layout', 'Core', 'heroicon-o-rectangle-group', true, false);
    }

    public function preview(MakerInputData $input): MakerPreviewData
    {
        $key = $this->key($input);
        $from = $this->from($input);

        return new MakerPreviewData(
            maker: $input->maker,
            files: collect(),
            databaseRecords: collect([
                new MakerDatabaseRecordData(Layout::class, 'create', array_filter(['key' => $key, 'name' => $this->name($input, $key), 'containers_from' => $from])),
            ]),
            commands: collect([sprintf('php artisan capell:make:layout %s --name="%s"%s', $key, $this->name($input, $key), $from === null ? '' : ' --from=' . $from)]),
            notes: collect([
                "Place widgets in the layout's containers in the admin panel under Layouts.",
                'Assign the layout to a page in the page editor.',
            ]),
        );
    }

    public function run(MakerInputData $input): MakerResultData
    {
        throw_unless($input->databaseWrites, RuntimeException::class, 'Creating a layout writes to the database; run it with database writes enabled.');

        $preview = $this->preview($input);
        $key = $this->key($input);
        $layout = CreateLayoutAction::run($key, $this->name($input, $key), $this->from($input));

        return new MakerResultData(
            maker: $input->maker,
            files: collect(),
            databaseRecords: collect([
                new MakerDatabaseRecordData(Layout::class, 'created', ['id' => $layout->getKey(), 'key' => $layout->key, 'name' => $layout->name, 'containers' => count($layout->containers ?? [])]),
            ]),
            commands: $preview->commands,
            notes: $preview->notes,
            successful: true,
        );
    }

    private function key(MakerInputData $input): string
    {
        // capell:make passes only --name, so the key falls back to a slug of the name.
        $key = trim((string) ($input->values['key'] ?? ''));

        return $key !== '' ? $key : Str::slug(trim((string) ($input->values['name'] ?? '')));
    }

    private function name(MakerInputData $input, string $key): string
    {
        $name = trim((string) ($input->values['name'] ?? ''));

        return $name !== '' ? $name : Str::headline($key);
    }

    private function from(MakerInputData $input): ?string
    {
        $from = trim((string) ($input->values['from'] ?? $input->values['source'] ?? ''));

        return $from !== '' ? $from : null;
    }
}
