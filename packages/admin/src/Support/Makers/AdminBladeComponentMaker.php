<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Makers;

use Capell\Core\Data\Makers\MakerDefinitionData;
use Capell\Core\Data\Makers\MakerInputData;
use Capell\Core\Data\Makers\MakerPreviewData;
use Capell\Core\Support\Makers\AbstractFileMaker;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

class AdminBladeComponentMaker extends AbstractFileMaker
{
    public function __construct(
        private readonly ComponentSourceResolver $sources,
    ) {}

    public function definition(): MakerDefinitionData
    {
        return new MakerDefinitionData('admin.component', 'Frontend Component', 'Create a host-app Blade component view', 'Frontend', 'heroicon-o-puzzle-piece', false, true);
    }

    protected function buildPreview(MakerInputData $input): MakerPreviewData
    {
        $componentType = (string) ($input->values['type'] ?? 'Component');
        $componentDirectory = Str::kebab($componentType);
        $name = Str::kebab((string) ($input->values['name'] ?? 'custom-component'));
        $source = $this->sources->resolve($componentType, isset($input->values['source']) ? (string) $input->values['source'] : null);
        $contents = $this->componentContents($source['path']);

        return $this->previewData(
            $input,
            collect([$this->fileData(resource_path('views/components/' . $componentDirectory . '/' . $name . '.blade.php'), $contents, $input->force)]),
            collect(['php artisan capell:make admin.component --type=' . $componentType . ' --name="' . $name . '"']),
            collect(['Run php artisan capell:cache-components after creating new components.']),
        );
    }

    private function componentContents(?string $sourcePath): string
    {
        $filesystem = resolve(Filesystem::class);

        if ($sourcePath !== null && $filesystem->exists($sourcePath)) {
            return (string) $filesystem->get($sourcePath);
        }

        return $this->renderStub(__DIR__ . '/../../../stubs/makers/component.blade.stub', []);
    }
}
