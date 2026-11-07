# Build a client site

This guide takes a new project from `laravel new` to a branded site with a custom page type, ready to deploy. Steps 1 to 4 were run on a clean starter-kit project.

## Before you start

You need PHP 8.4+, Composer, SQLite and the [Laravel installer](https://laravel.com/docs/installation).

## 1. Create the project

```bash
laravel new my-studio --using=capell-app/starter-kit
cd my-studio
php artisan serve
```

Open `http://localhost:8000` for the site and `http://localhost:8000/admin` for Admin. Sign in as `admin@example.test` with the password in `storage/app/private/starter-admin-password`, then store it securely and delete that file. Keep port 8000 free, because the site is bound to `APP_URL`.

`laravel new` also suggests `npm install`, `npm run build` and `composer run dev`. The build step only prints a confirmation, and `composer run dev` runs `php artisan serve`.

## 2. Set the name and colours

In Admin, open the dashboard and change the **Studio settings** name and **Enquiry recipient**.

Colours are authored directly in `public/css/starter.css`. Change the variables at the top of the file and reload the page. No build runs:

```css
:root {
  --paper: #f3f0e8;
  --ink: #183c35;
  --accent: #ffb4a2;
}
```

## 3. Add a page type

Follow [Build your first page type](build-your-first-page-type.md). It adds a **Service** page with its own editor field and public output.

## 4. Edit and check the public page

1. In Admin, open **Pages**, edit a page and save it.
2. Open its address in a private browser window, or request it with no session:

```bash
curl -s http://localhost:8000/services/brand-sprint
```

The anonymous response is the proof. Admin showing the change only proves the save.

## 5. Accept enquiries

The default mail driver is `log`, so contact messages appear in `storage/logs/laravel.log`. Configure a real mail transport before launch. The contact form is ordinary Laravel code: `ContactRequest`, `ContactData` and `SendContactMessageAction`. Use it as the example for other forms.

## 6. Deploy

The starter kit includes `scripts/deploy.sh`. Before you run it, read the starter-kit [deployment notes](https://github.com/capell-app/starter-kit#deployment), then follow [Deploy recipes](../operations/deploy-recipes.md) for the routine order on your host.

## Next

- [Build your first page type](build-your-first-page-type.md)
- [Going live](../operations/going-live.md)
- [Coming from WordPress](coming-from-wordpress.md) or [Coming from Statamic](coming-from-statamic.md)
