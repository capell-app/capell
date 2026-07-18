<?php

declare(strict_types=1);

namespace Capell\Core\Support\Makers\BuiltIn;

use Capell\Core\Data\Makers\MakerDefinitionData;
use Capell\Core\Data\Makers\MakerInputData;
use Capell\Core\Data\Makers\MakerPreviewData;
use Capell\Core\Support\Makers\AbstractFileMaker;
use Illuminate\Support\Str;

final class AssetBladeComponentMaker extends AbstractFileMaker
{
    public function definition(): MakerDefinitionData
    {
        return new MakerDefinitionData('core.asset-blade-component', 'Asset Blade Component', 'Create an asset Blade component view', 'Frontend', 'heroicon-o-photo', false, true);
    }

    protected function buildPreview(MakerInputData $input): MakerPreviewData
    {
        $name = Str::kebab((string) ($input->values['name'] ?? 'custom-asset'));

        return $this->previewData(
            $input,
            collect([$this->fileData(resource_path('views/components/asset/' . $name . '.blade.php'), $this->renderStub(__DIR__ . '/../../../../stubs/makers/asset-component.blade.stub', []), $input->force)]),
            collect(['php artisan capell:make core.asset-blade-component --name="' . $name . '"']),
            collect(['Run php artisan capell:cache-components after creating new components.']),
        );
    }
}
