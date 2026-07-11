FROM debian:12-slim

ARG NODE_VERSION=22
ARG WWWGROUP=1000
ARG WWWUSER=1000

ENV DEBIAN_FRONTEND=noninteractive
ENV TZ=Europe/London

RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        apt-transport-https ca-certificates curl dnsutils git gnupg gosu imagemagick \
        libcap2-bin lsb-release mariadb-client openssh-client sqlite3 unzip wget zip zsh \
    && mkdir -p /etc/apt/keyrings \
    && curl -fsSL https://packages.sury.org/php/apt.gpg | gpg --dearmor -o /etc/apt/keyrings/sury-php.gpg \
    && echo "deb [signed-by=/etc/apt/keyrings/sury-php.gpg] https://packages.sury.org/php/ bookworm main" > /etc/apt/sources.list.d/sury-php.list \
    && curl -fsSL https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key | gpg --dearmor -o /etc/apt/keyrings/nodesource.gpg \
    && echo "deb [signed-by=/etc/apt/keyrings/nodesource.gpg] https://deb.nodesource.com/node_$NODE_VERSION.x nodistro main" > /etc/apt/sources.list.d/nodesource.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends \
        nodejs php8.4 php8.4-bcmath php8.4-cli php8.4-curl php8.4-gd \
        php8.4-igbinary php8.4-imagick php8.4-intl php8.4-mbstring php8.4-memcached \
        php8.4-msgpack php8.4-mysql php8.4-opcache php8.4-pcov php8.4-readline \
        php8.4-redis php8.4-sqlite3 php8.4-xml php8.4-xdebug php8.4-zip \
    && php -r "readfile('https://getcomposer.org/installer');" | php -- --install-dir=/usr/bin --filename=composer \
    && apt-get -y autoremove \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

RUN groupadd --force -g $WWWGROUP capell \
    && useradd -ms /bin/zsh --no-user-group -g $WWWGROUP -u $WWWUSER capell \
    && phpdismod xdebug

COPY start-container /usr/local/bin/start-container
COPY php/php.ini /etc/php/8.4/cli/conf.d/99-capell.ini
COPY php/xdebug.ini /etc/php/8.4/mods-available/xdebug.ini

RUN chmod +x /usr/local/bin/start-container \
    && mkdir -p /home/capell/.config/composer /home/capell/.npm \
    && chown -R capell:capell /home/capell

WORKDIR /home/capell/current

ENTRYPOINT ["start-container"]
CMD ["sleep", "infinity"]
