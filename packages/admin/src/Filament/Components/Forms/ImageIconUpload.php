<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms;

use Filament\Forms\Components\FileUpload;
use Override;

class ImageIconUpload extends FileUpload
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('capell-admin::form.image'))
            ->image()
            ->acceptedFileTypes([
                'image/jpeg',
                'image/png',
                'image/webp',
                'image/gif',
                'image/avif',
            ])
            ->imagePreviewHeight('150')
            ->loadingIndicatorPosition('left')
            ->panelAspectRatio('2:1')
            ->panelLayout('integrated')
            ->preserveFilenames()
            ->removeUploadedFileButtonPosition('right')
            ->uploadButtonPosition('left')
            ->uploadProgressIndicatorPosition('left');
    }
}
