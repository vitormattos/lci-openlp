FROM php:8.1

RUN apt-get update

RUN apt-get install -y \
        git \
        libicu-dev \
        libzip-dev \
        procps \
        unzip \
        && docker-php-ext-install opcache \
        && docker-php-ext-install zip

# Install xdebug
RUN pecl install xdebug-3.1.5 \
    && docker-php-ext-enable xdebug

COPY --from=composer:2.4 /usr/bin/composer /usr/bin/composer

COPY config/php.ini /usr/local/etc/php/conf.d/

WORKDIR /lci