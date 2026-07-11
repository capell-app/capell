<?php

declare(strict_types=1);

namespace Capell\Frontend\Data;

use Illuminate\Http\Request;

final class FrontendRuleContextData
{
    public function __construct(
        public readonly Request $request,
        public readonly mixed $site = null,
        public readonly mixed $layout = null,
        public readonly mixed $page = null,
        public readonly mixed $language = null,
    ) {}
}
