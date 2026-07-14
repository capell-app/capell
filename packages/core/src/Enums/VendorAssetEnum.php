<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

enum VendorAssetEnum: string
{
    case TailwindImport = 'tailwind_import';

    case TailwindPlugin = 'tailwind_plugin';

    case TailwindSource = 'tailwind_source';

    case TailwindThemeColor = 'tailwind_theme_color';

}
