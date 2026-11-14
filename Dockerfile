
FROM php:8.2-apache

# Instalar extensiones necesarias para MySQL
RUN apt-get update \
    && apt-get install -y --no-install-recommends libsqlite3-dev \
    && docker-php-ext-install mysqli pdo_mysql pdo_sqlite \
    && rm -rf /var/lib/apt/lists/*

# Habilitar mod_rewrite para las rutas de la API
RUN a2enmod rewrite headers

# Permitir reglas .htaccess
RUN printf '<Directory /var/www/html>\nAllowOverride All\nRequire all granted\n</Directory>\n' \
    > /etc/apache2/conf-available/aulaviva.conf \
    && a2enconf aulaviva

# Copiar la aplicación
COPY . /var/www/html/

WORKDIR /var/www/html

EXPOSE 80

COPY deploy/security.conf /etc/apache2/conf-enabled/security-aulaviva.conf
