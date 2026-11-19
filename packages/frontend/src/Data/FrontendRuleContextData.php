<?php

declare(strict_types=1);

namespace Capell\Frontend\Data;

use Illuminate\Http\Request;

/**
 * Kept only because the deprecated FrontendRuleCondition contract names it, so classes in
 * already-published extension releases still load. Removed in 2.0.
 *
 * @deprecated 1.x compatibility only; removed in 2.0. Nothing evaluates frontend rule conditions. Do not use this in new code.
 */
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
