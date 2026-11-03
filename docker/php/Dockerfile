# docker/php/Dockerfile
#
# PHP-FPM image za SyncOPS.
# Build context je koren repozitorijuma (vidi docker-compose.yml),
# pa su putanje u COPY naredbama relativne u odnosu na koren.

FROM php:8.3-fpm-alpine

# Runtime biblioteke
RUN apk add --no-cache \
        libsodium \
        icu-libs \
        tzdata

# PHP ekstenzije.
# PHPIZE_DEPS i *-dev paketi se instaliraju privremeno i brišu posle builda,
# da image ostane mali.
RUN apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        libsodium-dev \
        icu-dev \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        sodium \
        intl \
        opcache \
    && apk del .build-deps

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Konfiguracija
COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-syncops.ini

WORKDIR /app

# php-fpm sluša na 9000 i dostupan je samo nginx servisu preko interne mreže.
EXPOSE 9000

CMD ["php-fpm"]
