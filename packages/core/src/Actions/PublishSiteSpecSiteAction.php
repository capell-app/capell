<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Actions\Properties\EvaluatePropertyCompletenessAction;
use Capell\Core\Actions\Publishing\TransitionPublicationAction;
use Capell\Core\Contracts\Publishing\AuthorizesPublicationTransition;
use Capell\Core\Data\Publishing\PublicationTransitionRequestData;
use Capell\Core\Data\SiteSpec\CapellSiteSpecData;
use Capell\Core\Data\SiteSpec\CapellSiteSpecPageData;
use Capell\Core\Enums\Publishing\PublicationTransition;
use Capell\Core\Enums\Publishing\PublicationTransitionOutcome;
use Capell\Core\Events\SiteSpecPublished;
use Capell\Core\Exceptions\SiteSpecPublishRefusedException;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;
use Capell\Core\Support\SiteSpec\SiteSpecPageDataFactory;
use Capell\Core\Support\SiteSpec\SiteSpecPageSlug;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;

/**
 * Makes every page of an applied SiteSpec public in one call.
 *
 * A private spec holds its homepage back as a "Coming soon" placeholder and marks every
 * page noindex. Publishing restores the homepage copy from the spec (unless an editor has
 * already replaced the placeholder), lifts the private markers, and moves each page live
 * through the normal publication pipeline, then asks the frontend to clear what it cached.
 *
 * Everything that can be refused is checked before the first write, and the writes share
 * one transaction, so a failure names the page and leaves every page as it was.
 */
final class PublishSiteSpecSiteAction
{
    use AsFake;
    use AsObject;

    private const string PLACEHOLDER_TITLE = 'Coming soon';

    private const string PLACEHOLDER_CONTENT = '<h1>Coming soon</h1><p>This site is being prepared.</p>';

    public function __construct(private readonly AuthorizesPublicationTransition $authorizer) {}

    /**
     * @return int the number of pages that went live (pages already live are left alone)
     *
     * @throws RuntimeException naming each page that cannot be published
     */
    public function handle(CapellSiteSpecData $spec, Site $site, Authenticatable $actor): int
    {
        $language = Language::query()->where('code', $spec->language->code)->firstOrFail();
        $existing = PlanSiteSpecAction::make()->existingPagesBySlug($spec, $site);
        $now = CarbonImmutable::now();

        /** @var list<array{spec: CapellSiteSpecPageData, page: Page, translation: Translation, home: bool}> $targets */
        $targets = [];
        $problems = [];
        $problemPages = [];

        foreach ($this->orderedPages($spec) as $index => $pageSpec) {
            $found = $existing[SiteSpecPageSlug::for($pageSpec)] ?? null;

            if ($found === null) {
                $problems[] = sprintf('%s (not on this site yet; apply the spec first)', $pageSpec->name);
                $problemPages[] = $pageSpec->name;

                continue;
            }

            $request = new PublicationTransitionRequestData($found['page'], PublicationTransition::PublishNow, $actor, $now);

            if (! $this->authorizer->allows($request)) {
                $problems[] = sprintf('%s (you are not allowed to publish it)', $pageSpec->name);
                $problemPages[] = $pageSpec->name;
            } elseif (EvaluatePropertyCompletenessAction::run($found['page'])->blocksPublish()) {
                $problems[] = sprintf('%s (required properties are missing)', $pageSpec->name);
                $problemPages[] = $pageSpec->name;
            }

            $targets[] = ['spec' => $pageSpec, 'page' => $found['page'], 'translation' => $found['translation'], 'home' => $index === 0];
        }

        throw_if($problems !== [], SiteSpecPublishRefusedException::class, (string) __('capell-core::message.site_spec_publish_refused', [
            'pages' => implode('; ', $problems),
        ]), $problemPages);

        $published = DB::transaction(function () use ($targets, $language, $actor, $now): int {
            $count = 0;

            foreach ($targets as $target) {
                $this->liftPrivateState($target['spec'], $target['page'], $target['translation'], $language, $target['home']);

                $result = TransitionPublicationAction::run(new PublicationTransitionRequestData(
                    $target['page'],
                    PublicationTransition::PublishNow,
                    $actor,
                    $now,
                ));

                throw_unless(
                    in_array($result->outcome, [PublicationTransitionOutcome::Changed, PublicationTransitionOutcome::AlreadyCorrect], true),
                    SiteSpecPublishRefusedException::class,
                    (string) __('capell-core::message.site_spec_publish_refused', ['pages' => sprintf('%s (%s)', $target['spec']->name, $result->reasonKey)]),
                    [$target['spec']->name],
                );

                if ($result->changed()) {
                    $count++;
                }
            }

            return $count;
        });

        // Dispatched inline once the outermost transaction commits (at once when there is none):
        // a failed cache clear must reach the caller rather than leave live pages behind a
        // stale public cache, and nothing is cleared for pages a caller's rollback undoes.
        DB::afterCommit(static fn () => event(new SiteSpecPublished($site)));

        return $published;
    }

    /**
     * Restores the homepage copy and lifts the markers a private import wrote. Meta is only
     * replaced where it still holds exactly what the private import wrote, so a noindex an
     * editor chose, or one the spec itself asks for, is never lifted by publishing.
     */
    private function liftPrivateState(CapellSiteSpecPageData $pageSpec, Page $page, Translation $translation, Language $language, bool $home): void
    {
        $private = SiteSpecPageDataFactory::make($pageSpec, $language, $home, false);
        $public = SiteSpecPageDataFactory::make($pageSpec, $language, $home, true);
        /** @var array<string, mixed> $privateTranslation */
        $privateTranslation = $private['translations'][$language->code];
        /** @var array<string, mixed> $publicTranslation */
        $publicTranslation = $public['translations'][$language->code];

        $placeholder = $home
            && $translation->title === self::PLACEHOLDER_TITLE
            && $translation->content === self::PLACEHOLDER_CONTENT;

        $currentTranslationMeta = $translation->meta ?? [];

        // Page and translation are lifted together or not at all, so they never disagree about indexing.
        $lift = $this->holdsMarkers($currentTranslationMeta, $privateTranslation['meta'])
            && $this->holdsMarkers($page->meta ?? [], $private['meta']);

        if ($lift) {
            $translation->forceFill(['meta' => $this->lifted($currentTranslationMeta, $privateTranslation['meta'], $publicTranslation['meta'])]);
        }

        if ($placeholder) {
            // Only the untouched placeholder is replaced; an editor's own homepage is kept.
            $translation->forceFill([
                'title' => $publicTranslation['title'],
                'content' => $publicTranslation['content'],
                'meta' => array_merge($publicTranslation['meta'], Arr::only($currentTranslationMeta, ['slug']), ['summary' => $publicTranslation['summary']]),
            ]);
        }

        $translation->save();

        if ($lift) {
            $page->forceFill(['meta' => $this->lifted($page->meta ?? [], $private['meta'], $public['meta'])])->save();
        }
    }

    /**
     * True while every value the private import wrote is still there, so nobody has changed it since.
     *
     * @param  array<string|int, mixed>  $current
     * @param  array<string|int, mixed>  $private
     */
    private function holdsMarkers(array $current, array $private): bool
    {
        return array_all($private, static fn (mixed $value, string|int $key): bool => array_key_exists($key, $current) && $current[$key] === $value);
    }

    /**
     * The current meta with the private import's values swapped for the public ones; keys the
     * import did not write (slug, summary, an image) stay as they are.
     *
     * @param  array<string|int, mixed>  $current
     * @param  array<string|int, mixed>  $private
     * @param  array<string|int, mixed>  $public
     * @return array<string|int, mixed>
     */
    private function lifted(array $current, array $private, array $public): array
    {
        return array_merge(Arr::except($current, array_keys($private)), $public);
    }

    /** @return list<CapellSiteSpecPageData> */
    private function orderedPages(CapellSiteSpecData $spec): array
    {
        return array_values(collect($spec->pages)->sortBy('order')->all());
    }
}
