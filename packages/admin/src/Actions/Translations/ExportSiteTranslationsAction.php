<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Translations;

use Capell\Core\Models\Contracts\Translatable;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;
use Generator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a site's content translations as CSV, one row per translatable record
 * and target language.
 *
 * Blueprint content is stored as a single JSON blob per locale, so the export
 * carries that blob verbatim in `source_content` and `target_content` rather
 * than flattening it into fields. Rows are emitted for target languages that
 * have no translation yet, with the target columns left blank.
 *
 * @method static StreamedResponse run(Site $site, ?Language $language = null)
 */
class ExportSiteTranslationsAction
{
    use AsFake;
    use AsObject;

    /** @var list<string> */
    public const COLUMNS = [
        'translatable_type',
        'translatable_id',
        'record_label',
        'translation_id',
        'source_language',
        'source_title',
        'source_content',
        'source_meta',
        'target_language',
        'target_title',
        'target_content',
        'target_meta',
    ];

    private const CHUNK_SIZE = 200;

    public function handle(Site $site, ?Language $language = null): StreamedResponse
    {
        $rows = $this->rows($site, $language);

        return response()->streamDownload(
            function () use ($rows): void {
                $handle = fopen('php://output', 'wb');

                if ($handle === false) {
                    return;
                }

                fwrite($handle, "\u{FEFF}");
                fputcsv($handle, self::COLUMNS, escape: '\\');

                foreach ($rows as $row) {
                    fputcsv($handle, $row, escape: '\\');
                }

                fclose($handle);
            },
            $this->filename($site, $language),
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }

    /**
     * @return Generator<int, list<string>>
     */
    public function rows(Site $site, ?Language $language = null): Generator
    {
        $targetLanguages = $this->targetLanguages($site, $language);

        if ($targetLanguages->isEmpty()) {
            return;
        }

        $defaultLanguage = $this->defaultLanguage();

        foreach ($this->translatables($site) as $translatable) {
            /** @var Collection<int, Translation> $translations */
            $translations = $translatable->translations()->get()->keyBy('language_id');
            $source = $defaultLanguage instanceof Language
                ? $translations->get($defaultLanguage->id)
                : null;

            foreach ($targetLanguages as $targetLanguage) {
                if ($defaultLanguage instanceof Language && $targetLanguage->id === $defaultLanguage->id) {
                    continue;
                }

                yield $this->row($translatable, $source, $translations->get($targetLanguage->id), $defaultLanguage, $targetLanguage);
            }
        }
    }

    /**
     * @return Generator<int, Model&Translatable>
     */
    private function translatables(Site $site): Generator
    {
        yield $site;

        foreach (Page::query()->where('site_id', $site->id)->orderBy('id')->lazyById(self::CHUNK_SIZE) as $page) {
            yield $page;
        }
    }

    /**
     * @return Collection<int, Language>
     */
    private function targetLanguages(Site $site, ?Language $language): Collection
    {
        if ($language instanceof Language) {
            return new Collection([$language]);
        }

        /** @var Collection<int, Language> $languages */
        $languages = $site->languages()->ordered()->get();

        return $languages->unique('id')->values();
    }

    private function defaultLanguage(): ?Language
    {
        /** @var class-string<Language> $model */
        $model = Language::class;

        $language = $model::query()->where('default', true)->first();

        return $language instanceof Language ? $language : null;
    }

    /**
     * @return list<string>
     */
    private function row(
        Model $translatable,
        ?Translation $source,
        ?Translation $target,
        ?Language $defaultLanguage,
        Language $targetLanguage,
    ): array {
        return [
            $translatable->getMorphClass(),
            (string) $translatable->getKey(),
            $this->label($translatable),
            $target instanceof Translation ? (string) $target->id : '',
            $defaultLanguage instanceof Language ? $defaultLanguage->code : '',
            $this->raw($source, 'title'),
            $this->raw($source, 'content'),
            $this->raw($source, 'meta'),
            $targetLanguage->code,
            $this->raw($target, 'title'),
            $this->raw($target, 'content'),
            $this->raw($target, 'meta'),
        ];
    }

    private function label(Model $translatable): string
    {
        $name = $translatable->getAttribute('name');

        return is_string($name) ? $name : '';
    }

    private function raw(?Translation $translation, string $attribute): string
    {
        if (! $translation instanceof Translation) {
            return '';
        }

        $value = $translation->getRawOriginal($attribute);

        if (is_string($value)) {
            return $value;
        }

        if ($value === null) {
            return '';
        }

        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function filename(Site $site, ?Language $language): string
    {
        return sprintf(
            'translations-site-%d%s-%s.csv',
            $site->id,
            $language instanceof Language ? '-' . $language->code : '',
            now()->format('Ymd-His'),
        );
    }
}
