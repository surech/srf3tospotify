FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libcurl4-openssl-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libonig-dev \
        libpng-dev \
        unzip \
    && docker-php-ext-configure gd --with-jpeg \
    && docker-php-ext-install curl gd intl mbstring pdo_mysql \
    && pecl install pcov \
    && docker-php-ext-enable pcov \
    && a2enmod headers rewrite \
    && rm -rf /var/lib/apt/lists/*

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/html