<?php

declare(strict_types=1);

namespace Capell\Frontend\Support;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Frontend\Contracts\FrontendContextReader;
use Capell\Frontend\Providers\FrontendServiceProvider;
use Capell\Frontend\Settings\FrontendSettings;
use Illuminate\Support\Traits\Macroable;

final class CapellFrontendContext
{
    use Macroable;

    public function __construct(
        private FrontendContextReader $contextReader,
    ) {}

    public function contextReader(): FrontendContextReader
    {
        return $this->contextReader;
    }

    public function site(): ?Site
    {
        return $this->contextReader->site();
    }

    public function language(): ?Language
    {
        return $this->contextReader->language();
    }

    public function page(): ?Pageable
    {
        return $this->contextReader->page();
    }

    public function layout(): ?Layout
    {
        return $this->contextReader->layout();
    }

    public function theme(): ?Theme
    {
        return $this->contextReader->theme();
    }

    /** @return array<string,mixed> */
    public function params(): array
    {
        return $this->contextReader->params();
    }

    public function slug(): ?string
    {
        return $this->contextReader->slug();
    }

    public function isError(): bool
    {
        return $this->contextReader->isError();
    }

    public function setFrontendData(string $key, mixed $value): self
    {
        $this->contextReader->setFrontendData($key, $value);

        return $this;
    }

    /**
     * @return mixed|array<string,mixed>
     */
    public function getFrontendData(?string $key = null): mixed
    {
        return $this->contextReader->getFrontendData($key);
    }

    public function settings(): FrontendSettings
    {
        return resolve(CapellCore::getPackage(FrontendServiceProvider::$packageName)->setting);
    }
}
