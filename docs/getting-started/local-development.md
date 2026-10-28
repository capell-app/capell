# Run Capell locally on Mac or Windows

Capell runs inside a Laravel application. For a disposable evaluation, Docker runs PHP, Composer and Node in a container while the application files and SQLite database remain in your project directory. Docker does not install Capell by itself: follow the container recipe to build the image, install the packages and start the application. See [How Capell works](how-capell-works.md) for the CMS concepts.

## Mac

Install and start a Docker-compatible engine with Docker Compose, such as [Docker Desktop for Mac](https://docs.docker.com/desktop/setup/install/mac-install/). Open Terminal in your Laravel project and check `docker compose version` and `docker info`. Then follow the [container guide](../development/container-development.md) from the beginning: create the shared PHP image files first, then use the disposable SQLite recipe.

## Windows

Install [WSL 2](https://learn.microsoft.com/windows/wsl/install) and [Docker Desktop for Windows](https://docs.docker.com/desktop/setup/install/windows-install/), start Docker Desktop and enable its WSL integration for your distribution. Open a WSL terminal, keep the project in the WSL filesystem, and check `docker compose version` and `docker info`. Follow the [container guide](../development/container-development.md) from the beginning: create the shared PHP image files first, then use the disposable SQLite recipe. Run its commands in the WSL terminal; they use Bash syntax rather than PowerShell.

## After the first start

The recipe binds the app to your computer's loopback address. Open `http://localhost:8000/admin/login`, or use the host port you selected. To stop the app without removing its data, run `docker compose -f compose.sqlite.yaml stop`; start it again with `docker compose -f compose.sqlite.yaml up -d app`. Run Artisan inside the app container, for example `docker compose -f compose.sqlite.yaml exec app php artisan capell:doctor`.

The recipe's `--fresh=force` install command deletes the selected database. Use it only for a new disposable application. For an existing installation, back up the database and media first, retain its `.env` and application key, and do not run the disposable install command against its data. The [container guide](../development/container-development.md) also explains a production-shaped deployment, which has different storage, queue, scheduler and security needs.
