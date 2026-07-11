<?php

declare(strict_types=1);

namespace Capell\Admin\Enums;

enum TinyEditorProfile: string
{
    case Default = 'default';

    case Simple = 'simple';

    case Full = 'full';

    case Minimal = 'minimal';

    case None = 'none';

    case Custom = 'custom';
}
