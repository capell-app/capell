<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Pages;

use Capell\Admin\Data\Pages\PageAuthoringInputData;
use Capell\Admin\Data\Pages\PageAuthoringResultData;
use Capell\Core\Actions\PageSavedAction;
use Capell\Core\Contracts\Redirects\RedirectUrlRecorder;
use Capell\Core\Models\Language;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class SavePageAuthoringAction
{
    use AsFake;
    use AsObject;

    public function handle(PageAuthoringInputData $inputData): PageAuthoringResultData
    {
        $formData = $inputData->formData;

        if ($inputData->previousUrls !== []) {
            $formData['_previous_urls'] = $inputData->previousUrls;
        }

        PageSavedAction::run($inputData->page, $formData);

        $redirectCount = $inputData->recordRedirects
            ? $this->recordRedirects($inputData)
            : 0;

        return new PageAuthoringResultData(redirectsRecorded: $redirectCount);
    }

    private function recordRedirects(PageAuthoringInputData $inputData): int
    {
        if ($inputData->previousUrls === [] || ! $inputData->page instanceof Model) {
            return 0;
        }

        $languages = Language::query()->whereIn('id', array_keys($inputData->previousUrls))->get();
        $redirectCount = 0;

        $languages->each(function (Language $language) use ($inputData, &$redirectCount): void {
            $url = $inputData->previousUrls[$language->id] ?? null;

            if (! is_string($url)) {
                return;
            }

            resolve(RedirectUrlRecorder::class)->record($inputData->page, $language, $url);
            $redirectCount++;
        });

        return $redirectCount;
    }
}
