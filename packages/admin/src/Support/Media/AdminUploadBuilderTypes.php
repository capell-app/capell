<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Media;

use Filament\Forms\Components\BaseFileUpload;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Builder\Block;
use Filament\Schemas\Components\Component;

final class AdminUploadBuilderTypes
{
    /** @return array<int, string>|null */
    public function forUpload(BaseFileUpload $upload): ?array
    {
        $container = $upload->getContainer();
        $types = [];
        $blockType = null;

        // Ownership follows the entire schema ancestry, independently of the
        // model root used to resolve already-persisted record values.
        while (($parent = $container->getParentComponent()) instanceof Component) {
            if ($parent instanceof Block) {
                $blockType = $parent->getName();
            }

            if ($parent instanceof Builder) {
                $path = $parent->getStatePath();
                if ($blockType === null || ! is_string($path) || $path === '') {
                    return null;
                }

                // Use the server-created schema, never submitted item types.
                $types[count(explode('.', $path))] = $blockType;
                $blockType = null;
            }

            $container = $parent->getContainer();
        }

        return $types;
    }
}
