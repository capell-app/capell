<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Redirects;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Enums\UrlTypeEnum;
use Capell\Core\Models\Language;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class AddRedirectUrlAction
{
    use AsFake;
    use AsObject;

    /**
     * @template TDeclaringModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Pageable<TDeclaringModel>  $page
     */
    public function handle(Pageable $page, Language $language, string $url): void
    {
        throw_unless($this->isValidRedirectUrl($url), InvalidArgumentException::class, sprintf("Invalid redirect url: '%s'. It must start with '/' and contain only URL-safe characters.", $url));
        throw_unless($this->siteSupportsLanguage($page, $language), InvalidArgumentException::class, sprintf("Language '%s' is not configured for site ID %d.", $language->getKey(), $page->site_id));

        $page->pageUrls()->firstOrCreate(
            [
                'language_id' => $language->id,
                'site_id' => $page->site_id,
                'url' => $url,
                'type' => UrlTypeEnum::Redirect,
            ],
            [
                'is_manual' => true,
            ],
        );
    }

    private function isValidRedirectUrl(string $url): bool
    {
        if ($url === '' || $url[0] !== '/') {
            return false;
        }

        // allow path-only URLs with common safe characters
        return (bool) preg_match('/^\/[A-Za-z0-9._~\-\/]*$/', $url);
    }

    /**
     * @template TDeclaringModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Pageable<TDeclaringModel>  $page
     */
    private function siteSupportsLanguage(Pageable $page, Language $language): bool
    {
        return $page->site()
            ->where(function (Builder $query) use ($language): void {
                $query
                    ->where('language_id', $language->getKey())
                    ->orWhereHas('siteDomains', fn (Builder $query): Builder => $query->where('language_id', $language->getKey()));
            })
            ->exists();
    }
}
