<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Pages;

use Capell\Admin\Data\Pages\PageUrlRedirectRequestData;
use Capell\Admin\Data\Pages\PageUrlRedirectResultData;
use Capell\Core\Contracts\Redirects\RedirectUrlRecorder;
use Capell\Core\Models\Language;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class RecordPageUrlRedirectsAction
{
    use AsFake;
    use AsObject;

    public function __construct(
        private readonly RedirectUrlRecorder $redirects,
    ) {}

    /**
     * @template TPageModel of Model
     *
     * @param  PageUrlRedirectRequestData<TPageModel>  $request
     */
    public function handle(PageUrlRedirectRequestData $request): PageUrlRedirectResultData
    {
        $acceptedUrls = [];

        foreach ($request->submittedUrls as $languageId => $url) {
            $expectedUrl = $request->expectedUrls[$languageId] ?? null;

            if (! is_string($expectedUrl) || ! hash_equals($expectedUrl, $url)) {
                continue;
            }

            $acceptedUrls[$languageId] = $url;
        }

        if ($acceptedUrls === []) {
            return new PageUrlRedirectResultData(
                acceptedCount: 0,
                recordedCount: 0,
            );
        }

        $languages = Language::query()
            ->whereIn('id', array_keys($acceptedUrls))
            ->get();
        $recordedCount = 0;

        foreach ($languages as $language) {
            $url = $acceptedUrls[$language->getKey()] ?? null;

            if (! is_string($url)) {
                continue;
            }

            $this->redirects->record($request->page, $language, $url);
            $recordedCount++;
        }

        return new PageUrlRedirectResultData(
            acceptedCount: count($acceptedUrls),
            recordedCount: $recordedCount,
        );
    }
}
