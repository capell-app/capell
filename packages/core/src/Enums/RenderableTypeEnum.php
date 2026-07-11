<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

enum RenderableTypeEnum: string
{
    case Asset = 'asset';
    case ContentBlock = 'content-block';
    case Layout = 'layout';
    case LayoutWidget = 'layout-widget';
    case Page = 'page';
    case Section = 'section';
}
