<?php

declare(strict_types=1);

namespace Capell\Frontend\Contracts;

interface FontMimeTypeResolverInterface
{
    /**
     * Returns the CSS font MIME type for a given URL or filename.
     */
    public function getFontFileType(string $font): string;
}
