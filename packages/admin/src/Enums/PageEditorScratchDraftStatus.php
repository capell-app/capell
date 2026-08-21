<?php

declare(strict_types=1);

namespace Capell\Admin\Enums;

enum PageEditorScratchDraftStatus: string
{
    case Discarded = 'discarded';
    case Locked = 'locked';
    case Saved = 'saved';
    case Unauthenticated = 'unauthenticated';
    case Unauthorized = 'unauthorized';
}
