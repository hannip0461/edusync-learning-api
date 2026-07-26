FROM php:8.3-apache-bookworm

ENV DEBIAN_FRONTEND=noninteractive \
    ACCEPT_EULA=Y \
    APACHE_DOCUMENT_ROOT=/var/www/html/public

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends ca-certificates curl gnupg2 unzip unixodbc-dev $PHPIZE_DEPS; \
    curl -fsSL https://packages.microsoft.com/config/debian/12/packages-microsoft-prod.deb -o /tmp/packages-microsoft-prod.deb; \
    dpkg -i /tmp/packages-microsoft-prod.deb; \
    rm /tmp/packages-microsoft-prod.deb; \
    apt-get update; \
    apt-get install -y --no-install-recommends msodbcsql18; \
    pecl install sqlsrv-5.13.1 pdo_sqlsrv-5.13.1; \
    docker-php-ext-enable sqlsrv pdo_sqlsrv; \
    a2enmod rewrite; \
    sed -ri -e "s!/var/www/html!${APACHE_DOCUMENT_ROOT}!g" /etc/apache2/sites-available/*.conf /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf; \
    printf 'ServerName localhost\n<Directory %s>\n    AllowOverride FileInfo\n    Require all granted\n</Directory>\n' "$APACHE_DOCUMENT_ROOT" > /etc/apache2/conf-available/edusync.conf; \
    a2enconf edusync; \
    apt-get purge -y --auto-remove unixodbc-dev $PHPIZE_DEPS; \
    rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist

COPY . ./
RUN composer dump-autoload --no-dev --classmap-authoritative

EXPOSE 80
