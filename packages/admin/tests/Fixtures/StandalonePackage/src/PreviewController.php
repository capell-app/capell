<?php

declare(strict_types=1);

namespace StandalonePackage;

final class PreviewController
{
    public function __invoke(): string
    {
        return 'standalone preview served';
    }
}
