<?php

declare(strict_types=1);

namespace Capell\Admin\Data\HeaderNavigation;

use Spatie\LaravelData\Data;

final class HeaderNavigationSiteData extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly ?string $editUrl = null,
        public readonly ?string $publicUrl = null,
    ) {}

    /**
     * @return array{id: int, name: string, edit_url: ?string, public_url: ?string}
     */
    public function toRecord(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'edit_url' => $this->editUrl,
            'public_url' => $this->publicUrl,
        ];
    }
}
