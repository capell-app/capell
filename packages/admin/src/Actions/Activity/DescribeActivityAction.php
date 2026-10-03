<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Activity;

use Capell\Admin\Contracts\Activity\ActivityDecorator;
use Capell\Admin\Data\Activity\ActivityPresentationData;
use Capell\Admin\Support\Activity\DefaultActivityDecorator;
use Capell\Admin\Support\Activity\TranslationActivityDecorator;
use Capell\Core\Support\Permissions\SiteAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Spatie\Activitylog\Models\Activity;

/**
 * @method static ActivityPresentationData run(Activity $activity)
 */
final class DescribeActivityAction
{
    use AsFake;
    use AsObject;

    public function handle(Activity $activity): ActivityPresentationData
    {
        throw_unless(SiteAccess::current()->canUseRecord($activity), AuthorizationException::class);

        foreach ($this->decorators() as $decorator) {
            if ($decorator->supports($activity)) {
                return $decorator->decorate($activity);
            }
        }

        return resolve(DefaultActivityDecorator::class)->decorate($activity);
    }

    /**
     * @return list<ActivityDecorator>
     */
    private function decorators(): array
    {
        return [
            resolve(TranslationActivityDecorator::class),
            resolve(DefaultActivityDecorator::class),
        ];
    }
}
