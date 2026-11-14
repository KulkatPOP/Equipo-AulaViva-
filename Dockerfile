FROM php:8.2.35-apache

RUN apt-get update && apt-get upgrade -y && apt-get install -y --no-install-recommends libsqlite3-dev && docker-php-ext-install mysqli pdo_mysql pdo_sqlite && rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite headers

RUN printf '<Directory /var/www/html>\nAllowOverride All\nRequire all granted\n</Directory>\n' > /etc/apache2/conf-available/aulaviva.conf && a2enconf aulaviva

COPY . /var/www/html/

WORKDIR /var/www/html

EXPOSE 80

COPY deploy/security.conf /etc/apache2/conf-enabled/zzz-aulaviva.conf
