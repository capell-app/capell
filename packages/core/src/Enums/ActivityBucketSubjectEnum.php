<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

enum ActivityBucketSubjectEnum: string
{
    case PageView = 'page_view';
    case SearchTerm = 'search_term';
}
