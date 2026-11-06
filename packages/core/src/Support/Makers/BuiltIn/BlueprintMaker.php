<?php

declare(strict_types=1);

namespace Capell\Core\Support\Makers\BuiltIn;

use Capell\Core\Data\Makers\MakerDefinitionData;
use Capell\Core\Data\Makers\MakerInputData;
use Capell\Core\Data\Makers\MakerPreviewData;
use Capell\Core\Support\BlueprintSubjectRegistry;
use Capell\Core\Support\Makers\AbstractFileMaker;
use Illuminate\Support\Str;

final class BlueprintMaker extends AbstractFileMaker
{
    public function definition(): MakerDefinitionData
    {
        return new MakerDefinitionData('core.blueprint', 'Blueprint', 'Create a model that can carry Capell blueprints', 'Core', 'heroicon-o-document-plus', false, true);
    }

    protected function buildPreview(MakerInputData $input): MakerPreviewData
    {
        $name = $this->studlyName($input);

        return $this->previewData(
            $input,
            collect([
                $this->fileData(app_path('Blueprints/' . $name . '.php'), $this->renderStub(__DIR__ . '/../../../../stubs/makers/blueprint-model.stub', ['class' => $name]), $input->force),
                $this->fileData(base_path('tests/Feature/Generated/' . $name . 'BlueprintSubjectTest.php'), $this->renderStub(__DIR__ . '/../../../../stubs/makers/blueprint-test.stub', ['class' => $name, 'key' => Str::kebab($name)]), $input->force),
            ]),
            collect(['php artisan capell:make:blueprint ' . $name]),
            collect([
                'Create a migration for the model table with a nullable blueprint_id foreign key; the model already carries the Blueprintable contract.',
                'In an application, register the subject from a service provider boot method: resolve(' . BlueprintSubjectRegistry::class . '::class)->register(new \Capell\Core\Data\BlueprintSubjectDescriptorData(key: \'' . Str::kebab($name) . "', label: '" . $name . '\', modelClass: \\App\\Blueprints\\' . $name . "::class, ownerPackage: 'app/" . Str::kebab($name) . '\')). A package registers it from bootInstalledPackage() with $this->surface()->blueprintSubject() instead.',
            ]),
        );
    }
}
