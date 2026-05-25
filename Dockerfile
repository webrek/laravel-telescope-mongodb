FROM php:8.3-cli-alpine

RUN apk add --no-cache \
        git \
        unzip \
        bash \
        curl \
        openssl-dev \
        libzip-dev \
        icu-dev \
        oniguruma-dev \
        sqlite-dev \
        $PHPIZE_DEPS \
    && pecl install mongodb \
    && docker-php-ext-enable mongodb \
    && docker-php-ext-install \
        bcmath \
        intl \
        zip \
        pdo_sqlite \
        opcache \
    && apk del $PHPIZE_DEPS \
    && rm -rf /tmp/* /var/cache/apk/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1 \
    COMPOSER_MEMORY_LIMIT=-1

WORKDIR /package

CMD ["php", "-v"]
