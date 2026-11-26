FROM php:8.4.27-apache

RUN apt-get update \
    && apt-get upgrade -y \
    && apt-get install -y --no-install-recommends \
        libsqlite3-dev \
        libpq-dev \
        poppler-utils \
        libzip-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
    && docker-php-ext-configure gd \
        --with-freetype \
        --with-jpeg \
    && docker-php-ext-install -j2 \
        mysqli \
        pdo_mysql \
        pdo_sqlite \
        pdo_pgsql \
        gd \
        zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

RUN a2enmod rewrite headers

RUN printf '<Directory /var/www/html>\nAllowOverride All\nRequire all granted\n</Directory>\n' > /etc/apache2/conf-available/aulaviva.conf \
    && a2enconf aulaviva

WORKDIR /var/www/html

COPY composer.json composer.lock ./

RUN COMPOSER_ALLOW_SUPERUSER=1 composer install \
    --no-dev \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader \
    --no-scripts

COPY . /var/www/html/

EXPOSE 80

COPY deploy/security.conf /etc/apache2/conf-enabled/zzz-aulaviva.conf
COPY deploy/php-runtime.ini /usr/local/etc/php/conf.d/zz-aulaviva.ini