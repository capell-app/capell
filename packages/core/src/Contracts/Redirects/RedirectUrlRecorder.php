<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Redirects;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\Language;

interface RedirectUrlRecorder
{
    /**
     * @template TDeclaringModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Pageable<TDeclaringModel>  $pageable
     */
    public function record(Pageable $pageable, Language $language, string $url): void;
}
