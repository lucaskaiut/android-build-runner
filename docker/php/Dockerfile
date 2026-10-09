FROM php:8.4-fpm

ENV DEBIAN_FRONTEND=noninteractive \
    COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/root/.composer

RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        curl \
        unzip \
        zip \
        procps \
        libfcgi-bin \
        libzip-dev \
        libicu-dev \
        libonig-dev \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        mbstring \
        bcmath \
        pcntl \
        zip \
        intl \
        opcache \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

COPY php.ini /usr/local/etc/php/conf.d/99-app.ini
COPY fpm.conf /usr/local/etc/php-fpm.d/zzz-app.conf
COPY start.sh /usr/local/bin/start.sh
COPY scheduler-start.sh /usr/local/bin/scheduler-start.sh

RUN chmod +x /usr/local/bin/start.sh /usr/local/bin/scheduler-start.sh \
    && git config --global --add safe.directory /var/www/html

WORKDIR /var/www/html

EXPOSE 9000

CMD ["start.sh"]
