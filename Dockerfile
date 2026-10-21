FROM php:8.4-apache
RUN apt-get update && apt-get install -y --no-install-recommends libxml2-dev libsqlite3-dev libsodium-dev libcurl4-openssl-dev libonig-dev unzip git && docker-php-ext-install pdo_sqlite mbstring curl sodium simplexml && a2enmod rewrite headers && rm -rf /var/lib/apt/lists/*
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/*.conf && printf '<Directory /var/www/html/public>\nAllowOverride All\nRequire all granted\n</Directory>\n' > /etc/apache2/conf-available/zion.conf && a2enconf zion
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY . .
RUN composer dump-autoload --no-dev --classmap-authoritative && mkdir -p storage/logs && chown -R www-data:www-data storage
EXPOSE 80
