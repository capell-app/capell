<?php

declare(strict_types=1);

namespace Capell\Frontend\Contracts;

interface HtmlMinifier
{
    public function minify(string $html): string;
}
