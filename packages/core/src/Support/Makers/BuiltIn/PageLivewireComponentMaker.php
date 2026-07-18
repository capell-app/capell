<?php

declare(strict_types=1);

namespace Capell\Core\Support\Makers\BuiltIn;

use Capell\Core\Data\Makers\MakerDefinitionData;
use Capell\Core\Data\Makers\MakerInputData;
use Capell\Core\Data\Makers\MakerPreviewData;
use Capell\Core\Support\Makers\AbstractFileMaker;
use Illuminate\Support\Str;

final class PageLivewireComponentMaker extends AbstractFileMaker
{
    public function definition(): MakerDefinitionData
    {
        return new MakerDefinitionData('core.page-livewire-component', 'Page Livewire Component', 'Create a Livewire page component', 'Frontend', 'heroicon-o-bolt', false, true);
    }

    protected function buildPreview(MakerInputData $input): MakerPreviewData
    {
        $class = $this->studlyName($input);
        $view = Str::kebab($class);

        return $this->previewData(
            $input,
            collect([
                $this->fileData(app_path('Livewire/' . $class . '.php'), $this->renderStub(__DIR__ . '/../../../../stubs/makers/page-livewire-class.stub', ['class' => $class, 'view' => $view]), $input->force),
                $this->fileData(resource_path('views/livewire/' . $view . '.blade.php'), $this->renderStub(__DIR__ . '/../../../../stubs/makers/page-livewire-view.stub', []), $input->force),
            ]),
            collect(['php artisan capell:make core.page-livewire-component --name="' . $class . '"']),
            collect(['Run php artisan capell:cache-components after creating new components.']),
        );
    }
}
