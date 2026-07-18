<?php

declare(strict_types=1);

namespace Capell\Core\Support\Makers\BuiltIn;

use Capell\Core\Data\Makers\MakerDefinitionData;
use Capell\Core\Data\Makers\MakerInputData;
use Capell\Core\Data\Makers\MakerPreviewData;
use Capell\Core\Support\Makers\AbstractFileMaker;
use Illuminate\Support\Str;

final class BlueprintMaker extends AbstractFileMaker
{
    public function definition(): MakerDefinitionData
    {
        return new MakerDefinitionData('core.blueprint', 'Blueprint', 'Create a Capell page blueprint class', 'Core', 'heroicon-o-document-plus', false, true);
    }

    protected function buildPreview(MakerInputData $input): MakerPreviewData
    {
        $name = $this->studlyName($input);

        return $this->previewData(
            $input,
            collect([$this->fileData(app_path('Blueprints/' . $name . 'Blueprint.php'), $this->renderStub(__DIR__ . '/../../../../stubs/makers/blueprint.stub', ['class' => $name, 'name' => Str::kebab($name), 'label' => Str::headline($name)]), $input->force)]),
            collect(['php artisan capell:make-blueprint ' . $name]),
            collect(['Register the blueprint through CapellCore::registerPageType().']),
        );
    }
}
