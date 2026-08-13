<?php

declare(strict_types=1);

namespace Capell\Core\Actions\EditorScratchDrafts;

use Capell\Core\Models\EditorScratchDraft;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;

final class SaveEditorScratchDraftAction
{
    use AsFake;
    use AsObject;

    public const int DEFAULT_TTL_MINUTES = 60 * 24 * 7;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(
        Model $record,
        Authenticatable $user,
        string $locale,
        string $context,
        array $payload,
        int $ttlMinutes = self::DEFAULT_TTL_MINUTES,
    ): EditorScratchDraft {
        $siteId = $record->getAttribute('site_id');
        $recordId = $record->getKey();

        throw_unless(is_numeric($siteId) && (int) $siteId > 0, RuntimeException::class, 'Editor scratch drafts require a site-scoped record.');
        throw_unless($recordId !== null && is_numeric($recordId), RuntimeException::class, 'Editor scratch drafts require a persisted record.');
        throw_if(trim($locale) === '' || trim($context) === '', RuntimeException::class, 'Editor scratch drafts require a locale and context.');

        $savedAt = CarbonImmutable::now('UTC');

        /** @var EditorScratchDraft $draft */
        $draft = EditorScratchDraft::query()->updateOrCreate(
            [
                'user_id' => $user->getAuthIdentifier(),
                'site_id' => (int) $siteId,
                'locale' => $locale,
                'record_type' => $record->getMorphClass(),
                'record_id' => (int) $recordId,
                'context' => $context,
            ],
            [
                'payload' => $payload,
                'content_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
                'saved_at' => $savedAt,
                'expires_at' => $savedAt->addMinutes(max(1, $ttlMinutes)),
            ],
        );

        return $draft->refresh();
    }
}
