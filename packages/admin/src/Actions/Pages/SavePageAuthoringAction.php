<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Pages;

use Capell\Admin\Data\Pages\PageAuthoringInputData;
use Capell\Admin\Data\Pages\PageAuthoringResultData;
use Capell\Admin\Data\Pages\PageUrlRedirectRequestData;
use Capell\Core\Actions\PageSavedAction;
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

        $redirectCount = 0;

        if ($inputData->recordRedirects
            && $inputData->previousUrls !== []
            && $inputData->page instanceof Model
        ) {
            $redirectCount = RecordPageUrlRedirectsAction::run(new PageUrlRedirectRequestData(
                page: $inputData->page,
                submittedUrls: $inputData->previousUrls,
                expectedUrls: $inputData->previousUrls,
            ))->recordedCount;
        }

        return new PageAuthoringResultData(redirectsRecorded: $redirectCount);
    }
}
