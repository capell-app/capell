<?php

declare(strict_types=1);

namespace Capell\Frontend\Contracts;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;

interface FrontendContextReader
{
    public function site(): ?Site;

    public function language(): ?Language;

    public function page(): ?Pageable;

    public function layout(): ?Layout;

    public function theme(): ?Theme;

    /** @return array<string,mixed> */
    public function params(): array;

    public function slug(): ?string;

    public function isError(): bool;

    /**
     * Store arbitrary frontend data in scoped state for this request.
     */
    public function setFrontendData(string $key, mixed $value): self;

    /**
     * Retrieve arbitrary frontend data from scoped state; when key is null, return full map.
     *
     * @return mixed|array<string,mixed>
     */
    public function getFrontendData(?string $key = null): mixed;
}
