<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Core\Contracts\Pageable;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

class RenderPageRecordDataAction
{
    use AsFake;
    use AsObject;

    public function handle(Pageable $page, array $replace): void
    {
        if ($page->translation === null) {
            return;
        }

        foreach (['title', 'content'] as $key) {
            if ($page->translation->{$key} === null) {
                continue;
            }

            if ($page->translation->{$key} === '') {
                continue;
            }

            if (! is_string($page->translation->{$key})) {
                continue;
            }

            $page->translation->setAttribute($key, __($page->translation->{$key}, $replace));
        }

        if (is_array($page->translation->meta) && $page->translation->meta !== []) {
            $meta = $page->translation->meta;

            foreach (['title', 'description', 'keywords', 'label'] as $key) {
                if (! isset($page->translation->meta[$key])) {
                    continue;
                }

                if ($page->translation->meta[$key] === null) {
                    continue;
                }

                if ($page->translation->meta[$key] === '') {
                    continue;
                }

                if (! is_string($page->translation->meta[$key])) {
                    continue;
                }

                $meta[$key] = __($meta[$key], $replace);
            }

            $page->translation->setAttribute('meta', $meta);
        }
    }
}
