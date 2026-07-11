<?php

declare(strict_types=1);

namespace Capell\Core\Contracts;

interface ParserContract
{
    /**
     * @return array<string, mixed>
     */
    public function parse(string $content): array;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function normalize(array $data): array;
}
