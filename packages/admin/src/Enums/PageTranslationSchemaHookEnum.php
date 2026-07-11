<?php

declare(strict_types=1);

namespace Capell\Admin\Enums;

enum PageTranslationSchemaHookEnum: string
{
    case BeforeTitle = 'before-title';
    case AfterTitle = 'after-title';
    case AfterContentEditor = 'after-content-editor';
    case AfterExtraContent = 'after-extra-content';
    case BeforeSearchMeta = 'before-search-meta';
    case AfterSearchMeta = 'after-search-meta';
}
