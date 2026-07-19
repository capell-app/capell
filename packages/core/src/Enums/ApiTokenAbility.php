<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

enum ApiTokenAbility: string
{
    case ContentDraftWrite = 'content:draft-write';
    case ContentPublish = 'content:publish';
    case ContentRead = 'content:read';
}
