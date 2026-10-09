<?php

declare(strict_types=1);

use Capell\Frontend\Actions\Fragments\ResolvePublicFragmentAssetVersionAction;
use Capell\Frontend\Actions\Fragments\ResolvePublicFragmentCacheIdentityAction;
use Capell\Frontend\Data\Fragments\PublicFragmentReferenceData;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

it('versions loaded asset and translation bytes independently of attribute order without lazy loading', function (): void {
    $asset = new class extends Model
    {
        use HasFactory;
    };
    $translation = new class extends Model
    {
        use HasFactory;
    };
    $asset->setRawAttributes(['id' => 1, 'value' => 'one']);
    $translation->setRawAttributes(['locale' => 'en', 'title' => 'First']);
    $asset->setRelation('translation', $translation);
    $first = ResolvePublicFragmentAssetVersionAction::run($asset);
    $asset->setRawAttributes(['value' => 'one', 'id' => 1]);
    $translation->setRawAttributes(['title' => 'First', 'locale' => 'en']);
    expect(ResolvePublicFragmentAssetVersionAction::run($asset))->toBe($first);
    $translation->setRawAttributes(['title' => 'Second', 'locale' => 'en']);
    expect(ResolvePublicFragmentAssetVersionAction::run($asset))->not->toBe($first);
    $asset->unsetRelation('translation');
    expect(ResolvePublicFragmentAssetVersionAction::run($asset))->not->toBe($first);
});

it('includes every fragment owner and context field in a deterministic cache identity', function (): void {
    $values = ['owner' => 'section', 'formatVersion' => 1, 'pageableType' => 'page', 'pageableId' => 1, 'siteId' => 2, 'languageId' => 3, 'contentVersion' => 'v1', 'ownerContext' => ['layoutId' => 4, 'assetId' => 5]];
    $first = ResolvePublicFragmentCacheIdentityAction::run(new PublicFragmentReferenceData(...$values));
    $reordered = $values;
    $reordered['ownerContext'] = array_reverse($values['ownerContext'], true);
    expect(ResolvePublicFragmentCacheIdentityAction::run(new PublicFragmentReferenceData(...$reordered)))->toBe($first);
    foreach (['owner' => 'widget', 'formatVersion' => 2, 'pageableType' => 'article', 'pageableId' => 2, 'siteId' => 3, 'languageId' => 4, 'contentVersion' => 'v2', 'ownerContext' => ['layoutId' => 6, 'assetId' => 5]] as $key => $value) {
        $changed = $values;
        $changed[$key] = $value;
        expect(ResolvePublicFragmentCacheIdentityAction::run(new PublicFragmentReferenceData(...$changed)))->not->toBe($first);
    }
});
