# Application Pages

Use application pages when a host route (an account area, a customer portal) should render inside the active theme, with its header, footer and navigation, while keeping its own Laravel route, controller, model binding, `password.confirm` and throttles.

This contract is experimental. Names and signatures may change before it is marked stable.

## How It Works

An application area pairs a key with a system page type. An editor creates and publishes one page of that type per site and language: the shell page. The `frontend.application:{area}` middleware resolves the site, the shell page by its type, the layout and the theme. The host route then renders its own content inside the theme's layout file.

Every response from the middleware is private:

- the request carries `PrivateFrontendResponse::ATTRIBUTE`, so the frontend cache policy never stores it
- the response sends `Cache-Control: no-store, private` and `Vary: Cookie`
- the response sends `X-Robots-Tag: noindex,nofollow`

## Register An Area

Create the shell page type once, for example in an install step or seeder. `applicationPageType()` always sets the type's cache time to Never, keeps it out of listings and the sitemap, and turns off critical CSS, whatever was set before:

```php
use Capell\Core\Support\Creator\BlueprintCreator;

resolve(BlueprintCreator::class)->applicationPageType('account', 'Account');
```

Register the area in a service provider:

```php
use Capell\Frontend\Data\ApplicationAreaData;
use Capell\Frontend\Support\Application\ApplicationAreaRegistry;

resolve(ApplicationAreaRegistry::class)->register(
    new ApplicationAreaData(key: 'account', pageType: 'account'),
);
```

## Add The Middleware To A Route

Put `frontend.application:{area}` after `web` and your authentication middleware:

```php
Route::middleware(['web', 'auth', 'frontend.application:account'])
    ->get('/account/orders', OrdersController::class);
```

Render the content with the `capell::application` view. It includes the theme's layout file with the usual page variables (`site`, `language`, `layout`, `theme`, `pageRecord`) plus `$applicationPage`:

```php
return view('capell::application', ['slot' => view('account.orders', $data)]);
```

A full-page Livewire component uses it as its layout:

```php
#[Layout('capell::application')]
final class Orders extends Component {}
```

`$applicationPage` is a `Capell\Frontend\Data\ApplicationPageData` with the area, the shell page, the layout file, the hydrated navigation and the robots directive. It is also on the request as `ApplicationPageData::ATTRIBUTE`.

Livewire update requests re-render only the component, never the layout, so they do not need the middleware.

## Supply Navigation

Bind `Capell\Frontend\Contracts\ApplicationNavigationResolver` to return the links shown around the area. The default binding returns none.

```php
public function resolve(ApplicationAreaData $area, Site $site, Language $language, Request $request): array
{
    return [new ApplicationNavigationItemData(label: __('Orders'), url: route('account.orders'), active: $request->routeIs('account.orders'))];
}
```

## When The Page Cannot Render

The middleware shows the plain, uncached unavailable page (503) with the reason and the fix when:

- no published shell page of the area's type exists for the site and language
- the shell page's type may be cached, because a signed-in page must never render inside a cacheable page

An area key that was never registered is a programming error and throws.
