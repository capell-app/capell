# Running Capell in containers

These recipes cover two different jobs:

- the SQLite recipe is a disposable, writable evaluation environment that follows the public quickstart;
- the production-shaped recipe builds immutable PHP-FPM and Nginx images, then runs the web process, queue worker, scheduler, and MySQL separately.

Both start from a Laravel application. Create one with the [quickstart](../getting-started/quickstart.md), then add the files below at the application root. Do not put `capell:install`, Composer, migrations, or asset compilation in a container entrypoint: more than one replica may start it, and a restart must not mutate a release.

Save this as `.dockerignore`. It keeps runtime secrets, local databases, host dependencies, and generated output out of image layers; the production build recreates dependencies and assets from the lockfiles.

<!-- capell-container-fixture: .dockerignore -->

<!-- prettier-ignore -->
```dockerignore
.git
.github
.env
.env.*
!.env.example
.npmrc
auth.json
node_modules
vendor
bootstrap/cache/*.php
database/*.sqlite
public/build
public/hot
public/storage
public/vendor
storage/app/public/*
storage/framework/cache/*
storage/framework/sessions/*
storage/framework/views/*
storage/logs/*
```

## Shared PHP image

Save this as `.docker/Dockerfile`. The `development` target runs Laravel's local server. The `production` target installs locked Composer and npm dependencies and builds assets. The `web` target contains only Nginx and the built public directory.

<!-- capell-container-fixture: Dockerfile -->

<!-- prettier-ignore -->
```dockerfile
# syntax=docker/dockerfile:1.7

FROM php:8.4-fpm-bookworm AS php

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        $PHPIZE_DEPS \
        curl \
        git \
        libcurl4-openssl-dev \
        libfreetype6-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libonig-dev \
        libpng-dev \
        libpq-dev \
        libsqlite3-dev \
        libxml2-dev \
        libzip-dev \
        unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        curl \
        dom \
        exif \
        gd \
        intl \
        mbstring \
        opcache \
        pcntl \
        pdo_mysql \
        pdo_pgsql \
        pdo_sqlite \
        simplexml \
        xmlreader \
        zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY --from=node:22-bookworm-slim /usr/local/ /usr/local/

ENV COMPOSER_ALLOW_SUPERUSER=1
WORKDIR /var/www/html

FROM php AS development

CMD ["php", "artisan", "serve", "--no-reload", "--host=0.0.0.0", "--port=8000"]

FROM php AS production

ENV APP_ENV=production
ENV APP_DEBUG=false

COPY --chown=www-data:www-data . .

RUN composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --prefer-dist \
        --optimize-autoloader \
    && npm ci --no-audit --no-fund \
    && npm run build \
    && php artisan capell:package-cache \
    && rm -rf node_modules \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    && chown -R www-data:www-data storage bootstrap/cache

USER www-data

CMD ["php-fpm", "-F"]

FROM nginx:1.28-alpine AS web

COPY --from=production /var/www/html/public /var/www/html/public
COPY .docker/nginx.conf /etc/nginx/conf.d/default.conf
```

## Disposable SQLite quickstart

Save this as `compose.sqlite.yaml`.

<!-- capell-container-fixture: compose.sqlite.yaml -->

<!-- prettier-ignore -->
```yaml
name: capell-sqlite

services:
  app:
    build:
      context: .
      dockerfile: .docker/Dockerfile
      target: development
    command: php artisan serve --no-reload --host=0.0.0.0 --port=8000
    env_file:
      - .env
    environment:
      APP_URL: http://localhost:${CAPELL_HTTP_PORT:-8000}
      DB_CONNECTION: sqlite
      DB_DATABASE: /var/www/html/database/database.sqlite
      QUEUE_CONNECTION: sync
    ports:
      - 127.0.0.1:${CAPELL_HTTP_PORT:-8000}:8000
    volumes:
      - .:/var/www/html
```

Create the database file, build the PHP 8.4 image, and require the public Installer package:

```bash
touch database/database.sqlite
docker compose -f compose.sqlite.yaml build app
docker compose -f compose.sqlite.yaml run --rm app composer require capell-app/installer
```

Fresh Laravel application archives do not include `package-lock.json`. Generate it once with the same containerised Node toolchain and commit it with the application; the production target deliberately uses `npm ci` and fails when the lockfile is absent:

```bash
docker compose -f compose.sqlite.yaml run --rm app npm install --package-lock-only --no-audit --no-fund
```

Run the same complete, non-interactive first-user flow used by release smoke. `--fresh=force` deletes the selected database, so use this only for the disposable SQLite application:

```bash
docker compose -f compose.sqlite.yaml run --rm app php artisan capell:install \
  --fresh=force \
  --demo \
  --package-mode=all \
  --theme=default \
  --seed \
  --url=http://localhost:8000 \
  --name="Capell owner" \
  --email=owner@example.test \
  --password='replace-this-local-password' \
  --clear-cache \
  --install-welcome-route \
  --no-interaction
```

Start the app and prove the install rather than treating a running container as success:

```bash
docker compose -f compose.sqlite.yaml up -d app
docker compose -f compose.sqlite.yaml exec -T app php artisan capell:doctor
curl --fail --location --show-error http://localhost:8000/ > /dev/null
curl --fail --location --show-error http://localhost:8000/admin/login > /dev/null
```

Open `http://localhost:8000/admin/login` and sign in with `owner@example.test` and the password supplied through `--password`. The `--name`, `--email`, and `--password` options create this first account together.

## Production-shaped topology

This topology is deliberately different from the SQLite evaluation. It uses a built release instead of a source bind mount, MySQL instead of a local file, persistent storage volumes, and separate web, PHP-FPM, queue, and scheduler processes. Put TLS and trusted-proxy handling in the external load balancer or ingress, and add monitored off-host database and media backups before serving traffic.

Save this as `.docker/nginx.conf`.

<!-- capell-container-fixture: nginx.conf -->

<!-- prettier-ignore -->
```nginx
server {
    listen 80;
    server_name _;
    root /var/www/html/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param HTTP_PROXY "";
        fastcgi_pass app:9000;
    }

    location ~ /\. {
        deny all;
    }
}
```

Save this as `.env.production`, replace every example secret, and keep the file outside source control. Generate `APP_KEY` with `php artisan key:generate --show` in a trusted environment.

<!-- capell-container-fixture: .env.production.example -->

<!-- prettier-ignore -->
```dotenv
APP_NAME=Capell
APP_ENV=production
APP_KEY=base64:replace-with-php-artisan-key-generate-show
APP_DEBUG=false
APP_URL=https://cms.example.com

DB_CONNECTION=mysql
DB_HOST=db
DB_PORT=3306
DB_DATABASE=capell
DB_USERNAME=capell
DB_PASSWORD=replace-with-a-database-password
DB_ROOT_PASSWORD=replace-with-a-different-root-password

CACHE_STORE=database
SESSION_DRIVER=database
QUEUE_CONNECTION=database
CAPELL_RELEASE_ROOT_MODE=immutable
CAPELL_HTTP_PORT=8080
```

Save this as `compose.production.yaml`.

<!-- capell-container-fixture: compose.production.yaml -->

<!-- prettier-ignore -->
```yaml
name: capell-production

x-app: &app
  build:
    context: .
    dockerfile: .docker/Dockerfile
    target: production
  image: ${CAPELL_APP_IMAGE:-capell-site-app:local}
  env_file:
    - .env.production
  restart: unless-stopped
  volumes:
    - capell-storage:/var/www/html/storage
  depends_on:
    db:
      condition: service_healthy

services:
  app:
    <<: *app

  web:
    build:
      context: .
      dockerfile: .docker/Dockerfile
      target: web
    image: ${CAPELL_WEB_IMAGE:-capell-site-web:local}
    restart: unless-stopped
    ports:
      - 127.0.0.1:${CAPELL_HTTP_PORT:-8080}:80
    volumes:
      - capell-storage:/var/www/html/storage:ro
    depends_on:
      - app

  worker:
    <<: *app
    command: php artisan queue:work --sleep=1 --tries=3 --timeout=90

  scheduler:
    <<: *app
    command: php artisan schedule:work

  db:
    image: mysql:8.4
    restart: unless-stopped
    environment:
      MYSQL_DATABASE: ${DB_DATABASE}
      MYSQL_USER: ${DB_USERNAME}
      MYSQL_PASSWORD: ${DB_PASSWORD}
      MYSQL_ROOT_PASSWORD: ${DB_ROOT_PASSWORD}
    volumes:
      - mysql-data:/var/lib/mysql
    healthcheck:
      test:
        - CMD-SHELL
        - mysqladmin ping -h 127.0.0.1 -u"$${MYSQL_USER}" -p"$${MYSQL_PASSWORD}"
      interval: 5s
      timeout: 5s
      retries: 20

volumes:
  capell-storage:
  mysql-data:
```

Run the guided installer once in a writable checkout before building this image, review its changes, and commit the application-owned files it creates. The image then contains that reviewed source and its locked dependencies. On the first deployment, run migrations and the unattended install as one release task against the production database. Supply `CAPELL_ADMIN_PASSWORD` from the release runner's secret store; the shell guard below fails before installation if it is absent. `CAPELL_RELEASE_ROOT_MODE=immutable` makes any unprepared source mutation fail instead of disappearing with the one-off container:

```bash
: "${CAPELL_ADMIN_PASSWORD:?Set CAPELL_ADMIN_PASSWORD in the release runner secret store}"
docker compose --env-file .env.production -f compose.production.yaml build app web
docker compose --env-file .env.production -f compose.production.yaml run --rm app php artisan migrate --force
docker compose --env-file .env.production -f compose.production.yaml run --rm --user root app php artisan capell:install \
  --production \
  --package-mode=all \
  --theme=default \
  --url=https://cms.example.com \
  --name="Capell owner" \
  --email=owner@example.com \
  --password="${CAPELL_ADMIN_PASSWORD}" \
  --clear-cache \
  --install-welcome-route \
  --no-interaction
docker compose --env-file .env.production -f compose.production.yaml run --rm app php artisan capell:doctor
docker compose --env-file .env.production -f compose.production.yaml up -d app web worker scheduler
```

The one-off install container runs as Docker `root` only because it publishes
application-owned assets into the image filesystem. PHP-FPM, workers, and the
scheduler still run as the image's unprivileged `www-data` user.

On later deployments, replace `capell:install` with the normal migration and `capell:upgrade` release steps. Run `capell:doctor` before starting traffic, and restart the worker after code changes.

The example publishes Nginx only on loopback port `8080`; the external proxy should be the only public listener. Scale `app` and `worker` independently. Run one scheduler process, not one per web replica.

## Existing users and `--user`

`--user=<email-or-id>` selects an existing account as the default author for generated content. It does not create an account, set a password, or provide login credentials. A fresh database therefore needs `--name`, `--email`, and `--password` together. Use `--user` only when the account already exists in the selected database.

## Filesystem and lifecycle checks

- Keep `storage/` and `bootstrap/cache/` writable by the PHP-FPM and worker users. The production image makes both writable before switching to runtime processes.
- Mount the same persistent `storage/` volume into PHP-FPM, workers, and Nginx when public media uses Laravel's storage link.
- Run Composer and asset builds while building the release, never from every container entrypoint.
- Run `php artisan storage:link` during release preparation when the application serves public media.
- Use database-backed or another shared cache, session, and queue driver when more than one replica runs. SQLite and `QUEUE_CONNECTION=sync` are for the disposable recipe only.
- Run `php artisan capell:doctor` in a one-off container after install or upgrade. A process being up does not prove Capell's migrations, packages, assets, permissions, and public routes are ready.

## Related

- [Quickstart](../getting-started/quickstart.md)
- [Install guide](../getting-started/install.md)
- [Going live](../operations/going-live.md)
- [Web server configuration](../operations/web-server.md)
- [Backups](../operations/backups.md)
- [Troubleshooting](../operations/troubleshooting.md)
