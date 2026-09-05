<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Properties;

use Capell\Core\Contracts\Media\MediaContract;
use Capell\Core\Data\Properties\AgentPropertyEntryData;
use Capell\Core\Enums\PropertyType;
use Capell\Core\Enums\UrlTypeEnum;
use Capell\Core\Exceptions\UrlMissingSiteDomainException;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Term;
use Capell\Core\Models\Translation;
use Capell\Core\Support\Media\MediaModel;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

/** Projects typed values into safe, public schema.org values. */
final class ProjectAgentSchemaValueAction
{
    use AsFake;
    use AsObject;

    public function handle(AgentPropertyEntryData $entry, ?int $siteId = null, ?Language $language = null): mixed
    {
        if ($entry->type->isReference()) {
            return $this->reference($entry, $siteId, $language);
        }

        if ($entry->type === PropertyType::Duration) {
            return $this->duration($entry->value, $entry->unit);
        }

        $value = $entry->value;
        if ($value instanceof DateTimeInterface) {
            return $value->format($entry->type === PropertyType::Date ? 'Y-m-d' : DATE_ATOM);
        }

        if (! is_scalar($value) || $value === '') {
            return null;
        }

        return match ($entry->type) {
            PropertyType::Money => ['@type' => 'PriceSpecification', 'price' => $value, 'priceCurrency' => $entry->currency],
            PropertyType::Dimension => [
                '@type' => 'QuantitativeValue', 'value' => $value,
                'unitCode' => match ($entry->unit) {
                    'kg' => 'KGM', 'g' => 'GRM', 'lb' => 'LBR', 'oz' => 'ONZ',
                    'm' => 'MTR', 'cm' => 'CMT', 'mm' => 'MMT', default => $entry->unit,
                },
            ],
            default => $value,
        };
    }

    private function reference(AgentPropertyEntryData $entry, ?int $siteId, ?Language $language): mixed
    {
        if ($entry->referenceId === null || $siteId === null) {
            return null;
        }

        return match ($entry->type) {
            PropertyType::EntryReference => $this->pageReference($entry->referenceId, $siteId, $language),
            PropertyType::TermReference => $this->termReference($entry->referenceId, $siteId),
            PropertyType::Media => $this->mediaReference($entry->referenceId, $siteId),
            default => null,
        };
    }

    /** @return array<string, mixed>|null */
    private function pageReference(int $pageId, int $siteId, ?Language $language): ?array
    {
        $page = Page::query()->published()->where('site_id', $siteId)->whereKey($pageId)
            ->whereHas('blueprint', static fn (Builder $blueprint): Builder => $blueprint->enabled()->accessible())
            ->first();
        if (! $page instanceof Page) {
            return null;
        }

        $languageId = $language?->id;
        $url = $page->pageUrls()->where('site_id', $siteId)->where('status', true)
            ->where(static fn (Builder $query): Builder => $query->whereNull('type')->orWhere('type', '!=', UrlTypeEnum::Redirect))
            ->whereHas('translation', static fn (Builder $query): Builder => $query
                ->when($languageId !== null, static fn (Builder $translation): Builder => $translation->where('language_id', $languageId)))
            ->whereHas('siteDomain', static fn (Builder $query): Builder => $query->where('status', true))
            ->when($languageId !== null, fn (Builder $query): Builder => $query->where('language_id', $languageId))
            ->with('siteDomain')->orderBy('id')->first();
        if ($url === null) {
            return null;
        }

        try {
            $publicUrl = $url->fullUrl();
        } catch (UrlMissingSiteDomainException) {
            return null;
        }

        $languageId = $language?->id;
        $translation = $languageId !== null
            ? $page->translations()->where('language_id', $languageId)->first()
            : $page->translation;

        $reference = ['@id' => $publicUrl, 'url' => $publicUrl];
        if (is_string($translation?->title) && $translation->title !== '') {
            $reference['name'] = $translation->title;
        }

        return $reference;
    }

    /** @return array<string, mixed>|null */
    private function termReference(int $termId, int $siteId): ?array
    {
        $term = Term::query()->whereKey($termId)
            ->whereHas('taxonomy', static fn (Builder $query): Builder => $query->where('site_id', $siteId))
            ->first();
        if (! $term instanceof Term || trim($term->name) === '') {
            return null;
        }

        $reference = ['name' => $term->name];
        if (is_string($term->semantic) && preg_match('/\Aschema:([A-Za-z][A-Za-z0-9]*)\z/', $term->semantic, $matches) === 1) {
            $reference['@type'] = $matches[1];
        }

        return $reference;
    }

    /** @return array<string, mixed>|null */
    private function mediaReference(int $mediaId, int $siteId): ?array
    {
        $media = MediaModel::query()->whereKey($mediaId)->whereNull('deleted_at')->first();
        if (! $media instanceof Model || ! $media instanceof MediaContract) {
            return null;
        }

        if (! method_exists($media, 'model')) {
            return null;
        }

        $owner = $media->getRelationValue('model');
        if (! $this->mediaOwnerBelongsToSite($owner, $siteId)) {
            return null;
        }

        if (! $this->mediaDiskIsPublic($media)) {
            return null;
        }

        try {
            $url = trim($media->getFullUrl());
        } catch (Throwable) {
            return null;
        }

        if ($url === '') {
            return null;
        }

        $query = parse_url($url, PHP_URL_QUERY);
        if (is_string($query) && preg_match('/(?:^|&)(?:signature|expires)=/i', $query) === 1) {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH);
        if (is_string($path) && preg_match('~/(?:admin|private)(?:/|$)~i', $path) === 1) {
            return null;
        }

        $reference = ['@type' => 'ImageObject', 'contentUrl' => $url, 'name' => $media->getName()];
        if ($media->getWidth() !== null) {
            $reference['width'] = $media->getWidth();
        }

        if ($media->getHeight() !== null) {
            $reference['height'] = $media->getHeight();
        }

        return $reference;
    }

    private function mediaDiskIsPublic(Model $media): bool
    {
        // Media publication is the configured disk visibility contract; the
        // owning page's publication window does not gate an explicit asset.
        $disk = $media->getAttribute('disk');
        if (! is_string($disk) || $disk === '') {
            return false;
        }

        $visibility = config(sprintf('filesystems.disks.%s.visibility', $disk));

        return is_string($visibility) && strtolower($visibility) === 'public';
    }

    private function mediaOwnerBelongsToSite(mixed $owner, int $siteId): bool
    {
        if (! $owner instanceof Model) {
            return false;
        }

        if ($owner instanceof Site) {
            return $owner->id === $siteId;
        }

        $ownerSiteId = $owner->getAttribute('site_id');
        if (is_numeric($ownerSiteId)) {
            return (int) $ownerSiteId === $siteId;
        }

        if (! $owner instanceof Translation) {
            return false;
        }

        $translatable = $owner->getRelationValue('translatable');

        return $translatable instanceof Model
            && (int) $translatable->getAttribute('site_id') === $siteId;
    }

    private function duration(mixed $value, ?string $unit): ?string
    {
        if (! is_numeric($value) || ! is_finite((float) $value) || ! is_string($unit)) {
            return null;
        }

        $number = (float) $value;
        if ($number < 0) {
            return null;
        }

        $format = static fn (float $amount, string $suffix): string => rtrim(rtrim(number_format($amount, 6, '.', ''), '0'), '.') . $suffix;

        return match (strtolower(trim($unit))) {
            'ms', 'millisecond', 'milliseconds' => 'PT' . $format($number / 1000, 'S'),
            's', 'sec', 'second', 'seconds' => 'PT' . $format($number, 'S'),
            'm', 'min', 'minute', 'minutes' => 'PT' . $format($number, 'M'),
            'h', 'hr', 'hour', 'hours' => 'PT' . $format($number, 'H'),
            'd', 'day', 'days' => 'P' . $format($number, 'D'),
            'w', 'week', 'weeks' => 'P' . $format($number, 'W'),
            default => null,
        };
    }
}
