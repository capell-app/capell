<?php

declare(strict_types=1);

namespace Capell\Core\Support\Makers\BuiltIn;

use Capell\Core\Actions\Structure\CreatePageTypeAction;
use Capell\Core\Contracts\Makers\Maker;
use Capell\Core\Data\Makers\MakerDatabaseRecordData;
use Capell\Core\Data\Makers\MakerDefinitionData;
use Capell\Core\Data\Makers\MakerInputData;
use Capell\Core\Data\Makers\MakerPreviewData;
use Capell\Core\Data\Makers\MakerResultData;
use Capell\Core\Enums\BlueprintSubjectEnum;
use Capell\Core\Models\Blueprint;
use Illuminate\Support\Str;
use RuntimeException;

final class PageTypeMaker implements Maker
{
    public function definition(): MakerDefinitionData
    {
        return new MakerDefinitionData('core.page-type', 'Page type', 'Create a page type editors can choose when they add a page', 'Core', 'heroicon-o-document-duplicate', true, false);
    }

    public function preview(MakerInputData $input): MakerPreviewData
    {
        $key = $this->key($input);
        $name = $this->name($input, $key);

        return new MakerPreviewData(
            maker: $input->maker,
            files: collect(),
            databaseRecords: collect([
                new MakerDatabaseRecordData(Blueprint::class, 'create', ['type' => BlueprintSubjectEnum::Page->value, 'key' => $key, 'name' => $name]),
            ]),
            commands: collect([sprintf('php artisan capell:make:page-type %s --name="%s"', $key, $name)]),
            notes: collect([
                'Add the fields this page type needs in the admin panel under Blueprints, or with a page schema extender in code.',
                'Editors can now choose the page type when they create a page.',
            ]),
        );
    }

    public function run(MakerInputData $input): MakerResultData
    {
        throw_unless($input->databaseWrites, RuntimeException::class, 'Creating a page type writes to the database; run it with database writes enabled.');

        $preview = $this->preview($input);
        $blueprint = CreatePageTypeAction::run($this->key($input), $this->name($input, $this->key($input)));

        return new MakerResultData(
            maker: $input->maker,
            files: collect(),
            databaseRecords: collect([
                new MakerDatabaseRecordData(Blueprint::class, 'created', ['id' => $blueprint->getKey(), 'type' => BlueprintSubjectEnum::Page->value, 'key' => $blueprint->key, 'name' => $blueprint->name]),
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
}
