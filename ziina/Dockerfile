FROM composer:2 AS plugin

WORKDIR /plugin

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts

COPY . .
RUN composer dump-autoload --no-dev --optimize

FROM wordpress:6.9.1-php8.3

COPY --from=plugin /plugin /usr/src/wordpress/wp-content/plugins/ziina
