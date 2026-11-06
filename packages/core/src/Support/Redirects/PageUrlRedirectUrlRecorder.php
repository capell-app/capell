<?php

declare(strict_types=1);

namespace Capell\Core\Support\Redirects;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Contracts\Redirects\BatchRedirectUrlRecorder;
use Capell\Core\Data\Redirects\RedirectUrlRecordData;
use Capell\Core\Enums\RedirectStatusCodeEnum;
use Capell\Core\Enums\UrlTypeEnum;
use Capell\Core\Exceptions\PageUrlCollisionException;
use Capell\Core\Models\Language;
use Capell\Core\Models\PageUrl;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class PageUrlRedirectUrlRecorder implements BatchRedirectUrlRecorder
{
    /**
     * @template TDeclaringModel of Model
     *
     * @param  Pageable<TDeclaringModel>  $pageable
     */
    public function record(Pageable $pageable, Language $language, string $url): void
    {
        throw_unless($pageable instanceof Model, InvalidArgumentException::class, 'Only model-backed pages can receive redirects.');

        $this->recordMany([new RedirectUrlRecordData($pageable, $language, $url)]);
    }

    /** @param  list<RedirectUrlRecordData>  $records */
    public function recordMany(array $records): void
    {
        if ($records === []) {
            return;
        }

        $taken = $this->takenUrls($records);
        $hasExistingCandidates = $taken !== [];
        $targets = $this->currentUrls($records);

        foreach ($records as $record) {
            $pageable = $record->pageable;
            $siteId = $pageable->site_id;
            $languageId = (int) $record->language->getKey();
            $takenKey = $this->takenKey($siteId, $languageId, $record->url);

            if (isset($taken[$takenKey])) {
                continue;
            }

            // MySQL collations can equate more than case (for example accents).
            // Let the column's collation decide when the batched search found
            // potential matches that do not share an ASCII-folded key.
            if ($hasExistingCandidates && in_array($this->driver(), ['mysql', 'mariadb'], true)
                && PageUrl::query()->where('site_id', $siteId)->where('language_id', $languageId)->where('url', $record->url)->exists()) {
                continue;
            }

            $redirect = new PageUrl([
                'site_id' => $siteId,
                'language_id' => $languageId,
                'pageable_type' => $pageable->getMorphClass(),
                'pageable_id' => $pageable->getKey(),
                'url' => $record->url,
                'target_url' => $targets[$this->targetKey($pageable->getMorphClass(), $pageable->getKey(), $languageId)] ?? null,
                'type' => UrlTypeEnum::Redirect,
                'status_code' => RedirectStatusCodeEnum::Permanent,
                'is_manual' => false,
                'status' => true,
            ]);
            // The page is already loaded; spare the observer looking it up again.
            $redirect->setRelation('page', $pageable);
            try {
                $redirect->save();
            } catch (PageUrlCollisionException) {
                // An earlier record in this batch can also collide according
                // to the database collation, even when its spelling differs.
                continue;
            }

            $taken[$takenKey] = true;
        }
    }

    /**
     * Every address already used in the records' sites and languages, keyed
     * for lookup, in one query.
     *
     * @param  list<RedirectUrlRecordData>  $records
     * @return array<string, true>
     */
    private function takenUrls(array $records): array
    {
        $taken = [];

        $query = PageUrl::query();
        $urls = array_values(array_unique(array_map(fn (RedirectUrlRecordData $record): string => $record->url, $records)));
        if ($this->driver() === 'sqlite') {
            $query->whereIn(DB::raw('lower(url)'), array_map(strtolower(...), $urls));
        } else {
            $query->whereIn('url', $urls);
        }

        $query
            ->whereIn('site_id', array_values(array_unique(array_map(fn (RedirectUrlRecordData $record): mixed => $record->pageable->site_id, $records))))
            ->whereIn('language_id', array_values(array_unique(array_map(fn (RedirectUrlRecordData $record): int => (int) $record->language->getKey(), $records))))
            ->toBase()
            ->get(['site_id', 'language_id', 'url'])
            ->each(function (object $row) use (&$taken): void {
                $taken[$this->takenKey($row->site_id, (int) $row->language_id, (string) $row->url)] = true;
            });

        return $taken;
    }

    /**
     * The current (non-redirect) address of every record's page and language.
     *
     * @param  list<RedirectUrlRecordData>  $records
     * @return array<string, string>
     */
    private function currentUrls(array $records): array
    {
        $byType = [];
        foreach ($records as $record) {
            $byType[$record->pageable->getMorphClass()][] = $record->pageable->getKey();
        }

        $languageIds = array_values(array_unique(array_map(fn (RedirectUrlRecordData $record): int => (int) $record->language->getKey(), $records)));
        $targets = [];

        PageUrl::query()
            ->whereIn('language_id', $languageIds)
            ->where(function (Builder $query) use ($byType): void {
                foreach ($byType as $type => $ids) {
                    $query->orWhere(fn (Builder $query): Builder => $query
                        ->where('pageable_type', $type)
                        ->whereIn('pageable_id', array_values(array_unique($ids))));
                }
            })
            ->where(fn (Builder $query): Builder => $query->whereNull('type')->orWhere('type', '!=', UrlTypeEnum::Redirect))
            ->orderBy('id')
            ->toBase()
            ->get(['pageable_type', 'pageable_id', 'language_id', 'url'])
            ->each(function (object $row) use (&$targets): void {
                $key = $this->targetKey((string) $row->pageable_type, $row->pageable_id, (int) $row->language_id);
                // Match value(): the first matching row wins.
                if (is_string($row->url) && ! isset($targets[$key])) {
                    $targets[$key] = $row->url;
                }
            });

        return $targets;
    }

    private function takenKey(mixed $siteId, int $languageId, string $url): string
    {
        return $siteId . "\0" . $languageId . "\0" . strtolower($url);
    }

    private function driver(): string
    {
        return DB::connection((new PageUrl)->getConnectionName())->getDriverName();
    }

    private function targetKey(string $type, mixed $id, int $languageId): string
    {
        return $type . "\0" . $id . "\0" . $languageId;
    }
}
